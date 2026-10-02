<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\EscalationExhaustedNotification;
use App\Domains\Incidents\Support\EscalationLadder;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Domains\TenantConfig\Actions\ResolveIncidentSla;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Throwable;

/**
 * Dueña del estado persistido de la escalera de SLA de un incidente
 * (`escalation_epoch/level/attempt`, `next_escalation_at`,
 * `escalation_exhausted_at`). Todo cambio de ese estado fuera del watchdog
 * pasa por aquí, siempre con la fila bloqueada:
 *
 * - arm(): (re)inicia la escalera en el nivel 0 con una generación nueva.
 *   Lo llaman la creación del incidente (automática o manual) y el release.
 *   La generación nueva invalida los jobs pendientes de la anterior y cambia
 *   las claves de aviso, así que un incidente re-armado vuelve a avisar.
 * - tightenForPriority(): una prioridad elevada adelanta el SLA si la
 *   escalera todavía no empezó (antes un incidente low → critical nunca
 *   escalaba).
 * - accelerate(): una emergencia confirmada o imposible de verificar ya avisó
 *   al nivel 0 con su propio mensaje; se da ese paso por hecho y se programa
 *   el siguiente, en vez de volver a avisar al nivel 0 al vencer el SLA.
 * - exhaust(): la escalera se quedó sin niveles. Entrada en la línea de
 *   tiempo y aviso a los super-admins de SAM.
 */
class ArmIncidentEscalation
{
    public function __construct(
        private readonly ResolveIncidentSla $resolveIncidentSla,
        private readonly NotifyEscalationLevel $notifyEscalationLevel,
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    /**
     * @param  string  $reason  código: `incident_created` | `manual_incident_created` | `released` | `priority_raised`
     */
    public function arm(Incident $incident, CarbonInterface $dueAt, string $reason): int
    {
        $epoch = DB::transaction(fn () => $this->armLocked($this->lock($incident), $dueAt));

        $this->syncArmed($incident, $epoch, $dueAt);
        $this->logArmed($incident->id, $reason, $epoch, $dueAt);

        return $epoch;
    }

    /**
     * Prioridad elevada: si su SLA vence antes que el paso pendiente (o no
     * había escalera), se adelanta `sla_due_at` y se re-arma. Si la escalera
     * ya avanzó, no se reinicia: sigue su curso. La decisión se toma con la
     * fila bloqueada: el watchdog no puede avanzar la escalera entre la
     * lectura y el re-armado.
     */
    public function tightenForPriority(Incident $incident): void
    {
        $input = ['incident_id' => $incident->id, 'incident_priority_id' => $incident->incident_priority_id];

        $outcome = DB::transaction(function () use ($incident) {
            $locked = $this->lock($incident);
            $locked->load('status');

            if ($locked->isTerminal() || IncidentSuppression::isUnderHumanControl($locked)) {
                return ['reason' => 'handled_or_terminal', 'calc' => null];
            }

            if ($locked->escalation_level > 0 || $locked->escalation_attempt > 1 || $locked->escalation_exhausted_at !== null) {
                return ['reason' => 'escalation_in_progress', 'calc' => ['escalation_level' => $locked->escalation_level, 'escalation_attempt' => $locked->escalation_attempt]];
            }

            $sla = $this->resolveIncidentSla->resolve($locked->team_id, $locked->incident_priority_id);

            if ($sla['sla_seconds'] === null) {
                return ['reason' => 'no_sla_for_priority', 'calc' => ['sla_source' => $sla['sla_source']]];
            }

            $now = now();
            $candidateDueAt = $now->copy()->addSeconds($sla['sla_seconds']);
            $pendingAt = $locked->next_escalation_at;

            // candidate_due_at = now_at + sla_seconds; se adelanta si pending_at es null o posterior.
            $calc = [
                'sla_seconds' => $sla['sla_seconds'],
                'sla_source' => $sla['sla_source'],
                'now_at' => $now->toIso8601String(),
                'candidate_due_at' => $candidateDueAt->toIso8601String(),
                'pending_at' => $pendingAt?->toIso8601String(),
            ];

            if ($pendingAt !== null && $pendingAt->lte($candidateDueAt)) {
                return ['reason' => 'not_earlier', 'calc' => $calc];
            }

            $locked->forceFill(['sla_due_at' => $candidateDueAt])->save();
            $epoch = $this->armLocked($locked, $candidateDueAt);

            return ['reason' => null, 'calc' => $calc, 'epoch' => $epoch, 'due_at' => $candidateDueAt];
        });

        if ($outcome['reason'] !== null) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.escalation.tightened', reason: $outcome['reason'], input: $input, calc: $outcome['calc']));

            return;
        }

        $incident->forceFill(['sla_due_at' => $outcome['due_at']])->syncOriginal();
        $this->syncArmed($incident, $outcome['epoch'], $outcome['due_at']);
        $this->logArmed($incident->id, 'priority_raised', $outcome['epoch'], $outcome['due_at']);

        DB::afterCommit(fn () => SystemLog::ok('incidents.escalation.tightened', input: $input, calc: $outcome['calc'], result: ['epoch' => $outcome['epoch']]));
    }

    /**
     * Generación nueva en el nivel 0 sobre una fila ya bloqueada; el job sale
     * tras el commit.
     */
    private function armLocked(Incident $locked, CarbonInterface $dueAt): int
    {
        $epoch = $locked->escalation_epoch + 1;

        $locked->forceFill([
            'escalation_epoch' => $epoch,
            'escalation_level' => 0,
            'escalation_attempt' => 1,
            'next_escalation_at' => $dueAt,
            'escalation_exhausted_at' => null,
        ])->save();

        CheckIncidentAcknowledgementJob::dispatch($locked->id, 0, 1, $epoch)
            ->delay($dueAt->isFuture() ? $dueAt : null)
            ->afterCommit();

        return $epoch;
    }

    private function syncArmed(Incident $incident, int $epoch, CarbonInterface $dueAt): void
    {
        $incident->forceFill([
            'escalation_epoch' => $epoch,
            'escalation_level' => 0,
            'escalation_attempt' => 1,
            'next_escalation_at' => $dueAt,
            'escalation_exhausted_at' => null,
        ])->syncOriginal();
    }

    private function logArmed(int $incidentId, string $reason, int $epoch, CarbonInterface $dueAt): void
    {
        $line = [
            'input' => ['incident_id' => $incidentId, 'arm_reason' => $reason],
            'result' => ['epoch' => $epoch, 'next_escalation_at' => $dueAt->toIso8601String()],
        ];
        DB::afterCommit(fn () => SystemLog::ok('incidents.escalation.armed', ...$line));
    }

    /**
     * El llamador ya avisó al nivel 0 con su propio mensaje (emergencia
     * confirmada, sin respuesta o imposible de verificar): ese paso cuenta
     * como disparado y se programa el siguiente. Si la escalera ya había
     * avanzado, no se toca.
     *
     * @param  string  $reason  código: `emergency_confirmed` | `verification_no_answer` | `verification_unavailable`
     */
    public function accelerate(Incident $incident, string $reason): void
    {
        $input = ['incident_id' => $incident->id, 'accelerate_reason' => $reason];
        $steps = $this->notifyEscalationLevel->steps($incident->team_id);

        $outcome = DB::transaction(function () use ($incident, $steps) {
            $locked = $this->lock($incident);

            if ($locked->escalation_level !== 0 || $locked->escalation_attempt !== 1 || $locked->escalation_exhausted_at !== null) {
                return ['skipped' => 'already_running', 'locked' => $locked, 'next' => null];
            }

            $next = EscalationLadder::next($steps, 0, 1);

            if ($next === null) {
                return ['skipped' => null, 'locked' => $locked, 'next' => null];
            }

            $nextAt = now()->addMinutes($next['delay_minutes']);

            $locked->forceFill([
                'escalation_level' => $next['level'],
                'escalation_attempt' => $next['attempt'],
                'next_escalation_at' => $nextAt,
            ])->save();

            CheckIncidentAcknowledgementJob::dispatch($incident->id, $next['level'], $next['attempt'], $locked->escalation_epoch)
                ->delay($nextAt)
                ->afterCommit();

            return ['skipped' => null, 'locked' => $locked, 'next' => $next];
        });

        $calc = [
            'escalation_level' => $outcome['locked']->escalation_level,
            'escalation_attempt' => $outcome['locked']->escalation_attempt,
            'steps_count' => count($steps),
        ];

        if ($outcome['skipped'] !== null) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.escalation.accelerated', reason: $outcome['skipped'], input: $input, calc: $calc));

            return;
        }

        if ($outcome['next'] === null) {
            $this->exhaust($outcome['locked'], count($steps));

            DB::afterCommit(fn () => SystemLog::skipped('incidents.escalation.accelerated', reason: 'no_next_level', input: $input, calc: $calc));

            return;
        }

        $result = [
            'next_level' => $outcome['next']['level'],
            'next_attempt' => $outcome['next']['attempt'],
            'delay_minutes' => $outcome['next']['delay_minutes'],
        ];
        DB::afterCommit(fn () => SystemLog::ok('incidents.escalation.accelerated', input: $input, calc: $calc, result: $result));
    }

    /**
     * Sin niveles por avisar y nadie atendió: queda constancia en la línea de
     * tiempo y se avisa a los super-admins. Idempotente por fila.
     */
    public function exhaust(Incident $incident, int $levelsCount): void
    {
        $input = ['incident_id' => $incident->id];

        $marked = DB::transaction(function () use ($incident, $levelsCount) {
            $locked = $this->lock($incident);

            if ($locked->escalation_exhausted_at !== null) {
                return false;
            }

            $locked->forceFill([
                'escalation_exhausted_at' => now(),
                'next_escalation_at' => null,
            ])->save();

            $this->appendTimelineEntry->execute(
                incident: $locked,
                entryType: TimelineEntryType::EscalationExhausted,
                actorType: TimelineActorType::System,
                title: 'Escalación agotada sin atención',
                description: "Se avisó a los {$levelsCount} niveles de escalación y nadie atendió el incidente. SAM avisó a su equipo de soporte.",
                payload: ['levels_count' => $levelsCount, 'epoch' => $locked->escalation_epoch],
            );

            return true;
        });

        if (! $marked) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.escalation.exhausted', reason: 'already_exhausted', input: $input));

            return;
        }

        $incident->forceFill(['escalation_exhausted_at' => now(), 'next_escalation_at' => null])->syncOriginal();

        DB::afterCommit(fn () => $this->alertPlatform($incident, $levelsCount));
    }

    private function alertPlatform(Incident $incident, int $levelsCount): void
    {
        $input = ['incident_id' => $incident->id];
        $superAdmins = User::query()->where('global_role', 'super_admin')->get();

        if ($superAdmins->isEmpty()) {
            SystemLog::degraded('incidents.escalation.exhausted', reason: 'no_super_admins', input: $input, calc: ['levels_count' => $levelsCount]);

            return;
        }

        $incident->loadMissing(['type', 'priority']);

        $details = [
            'team_id' => $incident->team_id,
            'team_name' => Team::query()->whereKey($incident->team_id)->value('name'),
            'incident_id' => $incident->id,
            'incident_reference' => $incident->reference(),
            'incident_type' => $incident->type?->code,
            'priority' => $incident->priority?->code,
            'levels_count' => $levelsCount,
            'opened_at' => $incident->opened_at?->toIso8601String(),
            'exhausted_at' => now()->toIso8601String(),
        ];

        try {
            LaravelNotification::sendNow($superAdmins, new EscalationExhaustedNotification($details));
        } catch (Throwable $e) {
            SystemLog::failed('incidents.escalation.exhausted', reason: 'alert_send_failed', input: $input, error: $e);

            return;
        }

        SystemLog::ok('incidents.escalation.exhausted', input: $input, calc: ['levels_count' => $levelsCount], result: ['super_admins_notified' => $superAdmins->count()]);
    }

    private function lock(Incident $incident): Incident
    {
        // Lookup por id del propio incidente que ya recibimos (su tenant es el
        // del llamador): sin scope para que funcione también en colas.
        return Incident::withoutGlobalScopes()
            ->whereKey($incident->getKey())
            ->where('team_id', $incident->team_id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
