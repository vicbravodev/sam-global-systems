<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use Illuminate\Support\Facades\DB;

class AcknowledgeIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    /**
     * Mark the incident as acknowledged by an operator, stopping the SLA
     * escalation chain (CheckIncidentAcknowledgementJob checks this flag).
     * Idempotent: a second acknowledgement keeps the first one. `$via` notes
     * the out-of-band channel (sms/whatsapp) when the ack came from a reply.
     */
    public function execute(Incident $incident, ?int $userId, ?string $via = null): Incident
    {
        if ($incident->acknowledged_at !== null) {
            return $incident;
        }

        return DB::transaction(function () use ($incident, $userId, $via) {
            $incident->update([
                'acknowledged_at' => now(),
                'acknowledged_by' => $userId,
            ]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Acknowledged,
                actorType: $userId !== null ? TimelineActorType::User : TimelineActorType::System,
                actorId: $userId,
                title: $via !== null ? "Incident acknowledged via {$via}" : 'Incident acknowledged',
                payload: array_filter([
                    'acknowledged_by' => $userId,
                    'via' => $via,
                    'sla_due_at' => $incident->sla_due_at?->toIso8601String(),
                    'within_sla' => $incident->sla_due_at === null || now()->lte($incident->sla_due_at),
                ], static fn ($value): bool => $value !== null),
            );

            $fresh = $incident->fresh(['status', 'priority', 'type']);

            broadcast(IncidentUpdatedBroadcast::fromModel($fresh));

            return $fresh;
        });
    }
}
