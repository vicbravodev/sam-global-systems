<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\ArmIncidentEscalation;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\EscalationLadder;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SLA watchdog for a single incident (Roadmap B6-P6). Dispatched with
 * `->delay($sla)` by ArmIncidentEscalation and re-armed per escalation step
 * until the incident is acknowledged, claimed, terminal, or the tenant's
 * escalation steps are exhausted.
 *
 * The chain's state lives on the incident row (`escalation_epoch/level/
 * attempt`, `next_escalation_at`), not only in this payload: each run locks
 * the row, discards itself when it belongs to an older generation (epoch) or
 * to a step already fired (a duplicate from the sweep or a retry), and
 * persists the next step before dispatching it. A lost or failed job is
 * re-dispatched by SweepOverdueEscalationsJob from that state.
 *
 * On each unacknowledged check the incident gets a `sla_breached` timeline
 * entry, transitions to `escalated` (first breach only), and the contacts of
 * the current `TenantEscalationConfig.steps_json` level are notified. Each
 * step may pin its channels and retry itself (`attempts`, `retry_minutes`)
 * before the chain moves to the next level (EscalationLadder).
 */
class CheckIncidentAcknowledgementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int DEFAULT_RETRY_MINUTES = EscalationLadder::DEFAULT_RETRY_MINUTES;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $incidentId,
        public readonly int $level = 0,
        public readonly int $attempt = 1,
        public readonly int $epoch = 0,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(
        EscalateIncident $escalateIncident,
        AppendTimelineEntry $appendTimelineEntry,
        NotifyEscalationLevel $notifyLevel,
        ArmIncidentEscalation $armEscalation,
    ): void {
        // Lookup de entrada sin scope: el job descubre aquí su tenant.
        $incident = Incident::withoutGlobalScopes()->find($this->incidentId);
        $input = $this->logInput();

        if ($incident === null) {
            SystemLog::skipped('incidents.ack_check.skipped', reason: 'incident_missing', input: $input);

            return;
        }

        // Trabaja dentro del tenant del propio registro: todo lo que sigue va
        // scopeado. Ver §2.1.
        TenantContext::set($incident->team_id);

        $steps = $notifyLevel->steps($incident->team_id);

        $outcome = DB::transaction(fn () => $this->run($incident, $steps, $escalateIncident, $appendTimelineEntry, $notifyLevel));

        if ($outcome === 'exhausted') {
            $armEscalation->exhaust($incident, count($steps));
        }
    }

    /**
     * Fallo definitivo: el estado persistido sigue apuntando a este paso y el
     * barrido lo re-despacha; aquí sólo queda constancia.
     */
    public function failed(?Throwable $exception): void
    {
        SystemLog::failed('incidents.ack_check.failed', reason: 'job_failed', input: $this->logInput(), error: $exception);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return 'skipped'|'rearmed'|'exhausted'
     */
    private function run(
        Incident $incident,
        array $steps,
        EscalateIncident $escalateIncident,
        AppendTimelineEntry $appendTimelineEntry,
        NotifyEscalationLevel $notifyLevel,
    ): string {
        $input = $this->logInput();

        $locked = Incident::query()
            ->with(['status', 'priority', 'type'])
            ->whereKey($incident->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Generación anterior (el incidente se re-armó tras un release o una
        // prioridad elevada): la escalera vigente es otra.
        if ($this->epoch !== $locked->escalation_epoch) {
            SystemLog::skipped('incidents.ack_check.skipped', reason: 'stale_epoch', input: $input, calc: ['current_epoch' => $locked->escalation_epoch]);

            return 'skipped';
        }

        // Paso ya disparado: duplicado del barrido, reintento o job viejo de
        // un paso que la aceleración de una emergencia ya dio por hecho.
        if (EscalationLadder::precedes($this->level, $this->attempt, $locked->escalation_level, $locked->escalation_attempt)
            || $locked->escalation_exhausted_at !== null) {
            SystemLog::skipped('incidents.ack_check.skipped', reason: 'stale_step', input: $input, calc: [
                'current_level' => $locked->escalation_level,
                'current_attempt' => $locked->escalation_attempt,
                'exhausted' => $locked->escalation_exhausted_at !== null,
            ]);

            return 'skipped';
        }

        // Acknowledged, closed or claimed: the chain ends (no notification)
        // and nothing stays pending for the sweep.
        $stopReason = match (true) {
            $locked->acknowledged_at !== null => 'acknowledged',
            $locked->isTerminal() => 'terminal',
            IncidentSuppression::isUnderHumanControl($locked) => 'human_control',
            default => null,
        };

        if ($stopReason !== null) {
            $locked->forceFill(['next_escalation_at' => null])->save();

            SystemLog::skipped('incidents.ack_check.skipped', reason: $stopReason, input: $input);

            return 'skipped';
        }

        // Delivered before the step is due (clock skew, a manual retry, the
        // sync queue in tests): never escalate early. The chain is not lost:
        // `next_escalation_at` stays persisted and SweepOverdueEscalationsJob
        // re-dispatches the step once it is due.
        $now = now();
        $dueAt = $locked->next_escalation_at
            ?? ($this->level === 0 && $this->attempt === 1 ? $locked->sla_due_at : null);

        if ($dueAt !== null && $now->lt($dueAt)) {
            // Same instant, at the second precision the log carries, so
            // seconds_until_due === due_at − now_at exactly.
            $nowAt = $now->copy()->startOfSecond();
            $dueAtSecond = $dueAt->copy()->startOfSecond();

            SystemLog::skipped('incidents.ack_check.skipped', reason: 'not_due_yet', input: $input, calc: [
                'sla_due_at' => $locked->sla_due_at?->copy()->startOfSecond()->toIso8601String(),
                'due_at' => $dueAtSecond->toIso8601String(),
                'now_at' => $nowAt->toIso8601String(),
                'seconds_until_due' => (int) $nowAt->diffInSeconds($dueAtSecond, false),
            ], result: ['rescued_by_sweep' => $locked->next_escalation_at !== null]);

            return 'skipped';
        }

        // Paso de comprobación tras el último nivel: nadie atendió en el
        // margen que se le dio, la escalera se agota (sin otro aviso).
        if ($this->level >= EscalationLadder::exhaustionLevel($steps)) {
            SystemLog::skipped('incidents.ack_check.chain_exhausted',
                reason: 'no_next_level',
                input: $input,
                calc: ['steps_count' => count($steps), 'exhaustion_grace_minutes' => EscalationLadder::EXHAUSTION_GRACE_MINUTES],
            );

            return 'exhausted';
        }

        $statusBefore = $locked->status?->code;
        $escalatedNow = false;

        // Retries of the same level only re-notify: the breach was already
        // recorded and the incident already transitioned on the first attempt.
        if ($this->attempt === 1) {
            $appendTimelineEntry->execute(
                incident: $locked,
                entryType: TimelineEntryType::SlaBreached,
                actorType: TimelineActorType::System,
                title: 'SLA incumplido',
                description: "El incidente no fue atendido antes de su SLA (nivel de escalamiento {$this->level}).",
                payload: [
                    'level' => $this->level,
                    'sla_due_at' => $locked->sla_due_at?->toIso8601String(),
                ],
            );

            if ($locked->status?->code !== IncidentStatusCode::Escalated->value) {
                $locked = $escalateIncident->execute(
                    $locked,
                    reason: 'SLA vencido sin atención (ACK).',
                    escalatedByType: IncidentCreatorType::System,
                );
                $escalatedNow = true;
            }
        }

        $this->notifyLevel($notifyLevel, $locked);

        SystemLog::ok('incidents.ack_check.breached',
            input: $input,
            calc: ['first_attempt_at_level' => $this->attempt === 1, 'status_before' => $statusBefore, 'steps_count' => count($steps)],
            result: ['escalated_now' => $escalatedNow, 'status_after' => $locked->status?->code],
        );

        return $this->scheduleNext($steps, $locked);
    }

    /**
     * @return array{incident_id: int, level: int, attempt: int}
     */
    private function logInput(): array
    {
        return ['incident_id' => $this->incidentId, 'level' => $this->level, 'attempt' => $this->attempt];
    }

    private function notifyLevel(NotifyEscalationLevel $notifyLevel, Incident $incident): void
    {
        // La generación entra en la clave a partir de la segunda: un incidente
        // re-armado (release, prioridad elevada) vuelve a avisar.
        $eventKey = "incident_sla_breached:{$incident->id}:{$this->level}"
            .($this->attempt > 1 ? ":a{$this->attempt}" : '')
            .($this->epoch > 1 ? ":e{$this->epoch}" : '');

        $notifyLevel->execute(
            incident: $incident,
            level: $this->level,
            eventKey: $eventKey,
            notificationType: 'incident.sla_breached',
            subject: 'SLA vencido sin atención: '.$incident->title,
            body: "El incidente superó su SLA sin acknowledgement (nivel {$this->level}, intento {$this->attempt}).",
        );
    }

    /**
     * Persist and dispatch the next step (EscalationLadder), or report the
     * chain as exhausted.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return 'rearmed'|'exhausted'
     */
    private function scheduleNext(array $steps, Incident $incident): string
    {
        $input = $this->logInput();
        $next = EscalationLadder::next($steps, $this->level, $this->attempt);

        if ($next === null) {
            return 'exhausted';
        }

        $nextAt = now()->addMinutes($next['delay_minutes']);

        $incident->forceFill([
            'escalation_level' => $next['level'],
            'escalation_attempt' => $next['attempt'],
            'next_escalation_at' => $nextAt,
        ])->save();

        self::dispatch($this->incidentId, $next['level'], $next['attempt'], $this->epoch)
            ->delay($nextAt)
            ->afterCommit();

        SystemLog::ok('incidents.ack_check.rearmed',
            input: $input,
            calc: ['mode' => $next['mode'], ...$next['calc']],
            result: ['next_level' => $next['level'], 'next_attempt' => $next['attempt'], 'delay_minutes' => $next['delay_minutes']],
        );

        return 'rearmed';
    }
}
