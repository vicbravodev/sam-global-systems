<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
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
 */
class CheckIncidentAcknowledgementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $incidentId,
        public readonly int $level = 0,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(
        EscalateIncident $escalateIncident,
        AppendTimelineEntry $appendTimelineEntry,
        SendNotification $sendNotification,
    ): void {
        $incident = Incident::withoutGlobalScopes()->with(['status', 'priority', 'type'])->find($this->incidentId);

        if ($incident === null || $incident->team_id === null) {
            return;
        }

        // Acknowledged or closed in time: the chain ends silently.
        if ($incident->acknowledged_at !== null || $incident->isTerminal()) {
            return;
        }

        // Delivered before the SLA actually expired (clock skew, sync queue in
        // tests): not a breach yet, never escalate early.
        if ($this->level === 0 && $incident->sla_due_at !== null && now()->lt($incident->sla_due_at)) {
            return;
        }

        $steps = $this->escalationSteps((int) $incident->team_id);

        $appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::SlaBreached,
            actorType: TimelineActorType::System,
            title: 'SLA breached',
            description: "Incident not acknowledged before its SLA (escalation level {$this->level}).",
            payload: [
                'level' => $this->level,
                'sla_due_at' => $incident->sla_due_at?->toIso8601String(),
            ],
        );

        if ($incident->status?->code !== IncidentStatusCode::Escalated->value) {
            $incident = $escalateIncident->execute(
                $incident,
                reason: 'SLA breached without acknowledgement.',
                escalatedByType: IncidentCreatorType::System,
            );
        }

        $this->notifyLevel($sendNotification, $incident, $steps[$this->level] ?? null);

        $this->scheduleNextLevel($steps);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function escalationSteps(int $teamId): array
    {
        $config = TenantEscalationConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        $steps = $config?->steps_json;

        return is_array($steps) ? array_values($steps) : [];
    }

    /**
     * @param  array<string, mixed>|null  $step
     */
    private function notifyLevel(SendNotification $sendNotification, Incident $incident, ?array $step): void
    {
        $payload = [
            'incident_id' => $incident->id,
            'incident_type' => $incident->type?->code,
            'severity' => $incident->priority?->code,
            'incident_title' => $incident->title,
            'escalation_level' => $this->level,
        ];

        // Explicit contacts on the step are addressed directly; without them
        // the notification fans out to the whole team (default recipients).
        $contacts = array_values(array_filter(
            (array) ($step['contacts'] ?? []),
            fn ($contact) => is_string($contact) && $contact !== '',
        ));

        if ($contacts !== []) {
            $payload['recipients'] = array_map(fn (string $address) => [
                'recipient_type' => 'external_contact',
                'address' => $address,
            ], $contacts);
        }

        $sendNotification->execute(
            teamId: (int) $incident->team_id,
            notificationType: 'incident.sla_breached',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: "incident_sla_breached:{$incident->id}:{$this->level}",
            payload: $payload,
            subject: 'SLA vencido sin atención: '.$incident->title,
            bodyPreview: "El incidente superó su SLA sin acknowledgement (nivel {$this->level}).",
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function scheduleNextLevel(array $steps): void
    {
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
