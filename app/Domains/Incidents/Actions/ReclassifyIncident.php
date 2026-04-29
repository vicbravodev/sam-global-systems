<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentType;
use Illuminate\Support\Facades\DB;

class ReclassifyIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function execute(
        Incident $incident,
        IncidentType $newType,
        ?IncidentPriority $newPriority = null,
        IncidentCreatorType $actorType = IncidentCreatorType::User,
        ?int $actorId = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $newType, $newPriority, $actorType, $actorId) {
            $previousTypeId = $incident->incident_type_id;
            $previousPriorityId = $incident->incident_priority_id;

            $updates = ['incident_type_id' => $newType->id];

            if ($newPriority !== null) {
                $updates['incident_priority_id'] = $newPriority->id;
            }

            $incident->update($updates);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Reclassified,
                actorType: $actorType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::System,
                actorId: $actorId,
                title: 'Incident reclassified',
                payload: [
                    'previous_type_id' => $previousTypeId,
                    'new_type_id' => $newType->id,
                    'previous_priority_id' => $previousPriorityId,
                    'new_priority_id' => $newPriority?->id ?? $previousPriorityId,
                ],
            );

            return $incident->fresh(['type', 'priority', 'status']);
        });
    }
}
