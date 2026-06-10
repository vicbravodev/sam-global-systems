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
     * Idempotent: a second acknowledgement keeps the first one.
     */
    public function execute(Incident $incident, int $userId): Incident
    {
        if ($incident->acknowledged_at !== null) {
            return $incident;
        }

        return DB::transaction(function () use ($incident, $userId) {
            $incident->update([
                'acknowledged_at' => now(),
                'acknowledged_by' => $userId,
            ]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Acknowledged,
                actorType: TimelineActorType::User,
                actorId: $userId,
                title: 'Incident acknowledged',
                payload: [
                    'acknowledged_by' => $userId,
                    'sla_due_at' => $incident->sla_due_at?->toIso8601String(),
                    'within_sla' => $incident->sla_due_at === null || now()->lte($incident->sla_due_at),
                ],
            );

            $fresh = $incident->fresh(['status', 'priority', 'type']);

            broadcast(IncidentUpdatedBroadcast::fromModel($fresh));

            return $fresh;
        });
    }
}
