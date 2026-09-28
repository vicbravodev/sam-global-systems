<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SLA watchdog for a single incident (Roadmap B6-P6). Dispatched with
 * `->delay($sla)` at incident creation — no per-minute cron — and re-armed
 * per escalation level until the incident is acknowledged, terminal, or the
 * tenant's escalation steps are exhausted.
 *
 * On each unacknowledged check the incident gets a `sla_breached` timeline
 * entry, transitions to `escalated` (first breach only — the transition also
 * fires the escalation automation + realtime broadcast), and the contacts of
 * the current `TenantEscalationConfig.steps_json` level are notified.
 *
 * Roadmap V2-A4: each step may pin its delivery channels (`channels`, e.g.
 * `["voice","sms"]` → `force_channels`) and retry itself (`attempts`, with
 * `retry_minutes` between retries) before the chain moves to the next level.
 */
class CheckIncidentAcknowledgementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int DEFAULT_RETRY_MINUTES = 5;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $incidentId,
        public readonly int $level = 0,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(
        EscalateIncident $escalateIncident,
        AppendTimelineEntry $appendTimelineEntry,
        NotifyEscalationLevel $notifyLevel,
    ): void {
        $incident = Incident::withoutGlobalScopes()->with(['status', 'priority', 'type'])->find($this->incidentId);

        if ($incident === null || $incident->team_id === null) {
            return;
        }

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($incident->team_id);

        // Acknowledged or closed in time: the chain ends silently.
        if ($incident->acknowledged_at !== null || $incident->isTerminal()) {
            return;
        }

        // Somebody already claimed it: a human is on it, the watchdog stays quiet.
        if (IncidentSuppression::isUnderHumanControl($incident)) {
            return;
        }

        // Delivered before the SLA actually expired (clock skew, sync queue in
        // tests): not a breach yet, never escalate early.
        if ($this->level === 0 && $this->attempt === 1 && $incident->sla_due_at !== null && now()->lt($incident->sla_due_at)) {
            return;
        }

        $steps = $notifyLevel->steps((int) $incident->team_id);

        // Retries of the same level only re-notify: the breach was already
        // recorded and the incident already transitioned on the first attempt.
        if ($this->attempt === 1) {
            $appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::SlaBreached,
                actorType: TimelineActorType::System,
                title: 'SLA incumplido',
                description: "El incidente no fue atendido antes de su SLA (nivel de escalamiento {$this->level}).",
                payload: [
                    'level' => $this->level,
                    'sla_due_at' => $incident->sla_due_at?->toIso8601String(),
                ],
            );

            if ($incident->status?->code !== IncidentStatusCode::Escalated->value) {
                $incident = $escalateIncident->execute(
                    $incident,
                    reason: 'SLA vencido sin atención (ACK).',
                    escalatedByType: IncidentCreatorType::System,
                );
            }
        }

        $this->notifyLevel($notifyLevel, $incident);

        $this->scheduleNext($steps);
    }

    private function notifyLevel(NotifyEscalationLevel $notifyLevel, Incident $incident): void
    {
        $eventKey = "incident_sla_breached:{$incident->id}:{$this->level}"
            .($this->attempt > 1 ? ":a{$this->attempt}" : '');

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
     * Retry the same level while its `attempts` budget lasts, then move to
     * the next one.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function scheduleNext(array $steps): void
    {
        $step = $steps[$this->level] ?? null;
        $stepAttempts = max(1, (int) ($step['attempts'] ?? 1));

        if ($this->attempt < $stepAttempts) {
            $retryMinutes = max(1, (int) ($step['retry_minutes'] ?? self::DEFAULT_RETRY_MINUTES));

            self::dispatch($this->incidentId, $this->level, $this->attempt + 1)
                ->delay(now()->addMinutes($retryMinutes));

            return;
        }

        $nextLevel = $this->level + 1;
        $next = $steps[$nextLevel] ?? null;

        if ($next === null) {
            return;
        }

        $currentOffset = (int) ($steps[$this->level]['delay_minutes'] ?? 0);
        $nextOffset = (int) ($next['delay_minutes'] ?? 0);
        $delayMinutes = max(1, $nextOffset - $currentOffset);

        self::dispatch($this->incidentId, $nextLevel)->delay(now()->addMinutes($delayMinutes));
    }
}
