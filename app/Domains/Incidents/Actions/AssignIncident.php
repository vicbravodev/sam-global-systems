<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentAssigned;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use Illuminate\Support\Facades\DB;

class AssignIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function execute(
        Incident $incident,
        AssigneeType $assigneeType,
        int $assigneeId,
        ?string $role = null,
        IncidentCreatorType $assignedByType = IncidentCreatorType::System,
        ?int $assignedById = null,
    ): IncidentAssignment {
        return DB::transaction(function () use ($incident, $assigneeType, $assigneeId, $role, $assignedByType, $assignedById) {
            IncidentAssignment::query()
                ->where('incident_id', $incident->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            $assignment = IncidentAssignment::query()->create([
                'incident_id' => $incident->id,
                'assigned_to_type' => $assigneeType,
                'assigned_to_id' => $assigneeId,
                'role' => $role,
                'assigned_at' => now(),
                'assigned_by_type' => $assignedByType,
                'assigned_by_id' => $assignedById,
            ]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Assigned,
                actorType: $assignedByType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::System,
                actorId: $assignedById,
                title: "Assigned to {$assigneeType->value} #{$assigneeId}",
                payload: [
                    'assignment_id' => $assignment->id,
                    'assigned_to_type' => $assigneeType->value,
                    'assigned_to_id' => $assigneeId,
                    'role' => $role,
                ],
            );

            IncidentAssigned::dispatch($incident->fresh(), $assignment);

            return $assignment;
        });
    }
}
