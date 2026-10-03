<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentAssigned;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Models\User;
use App\Support\TeamMembers;
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
        $this->guardAssignee($incident, $assigneeType, $assigneeId);

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
                title: $this->title($assigneeType, $assigneeId),
                payload: [
                    'assignment_id' => $assignment->id,
                    'assigned_to_type' => $assigneeType->value,
                    'assigned_to_id' => $assigneeId,
                    'role' => $role,
                ],
            );

            IncidentAssigned::dispatch($incident->freshOrFail(), $assignment);

            return $assignment;
        });
    }

    /**
     * Los ids de usuario y de team son globales: nunca asignar un incidente a
     * alguien de otro tenant (le daría acceso a sus datos y notificaciones).
     * Un super-admin sí puede figurar como asignado (soporte de SAM).
     */
    /**
     * "Asignado a Ana López", no "Assigned to user #12". El nombre sale de un
     * usuario ya validado como asignable en este team (guardAssignee).
     */
    private function title(AssigneeType $assigneeType, int $assigneeId): string
    {
        return match ($assigneeType) {
            AssigneeType::User => 'Asignado a '.(User::query()->whereKey($assigneeId)->value('name') ?? "usuario #{$assigneeId}"),
            AssigneeType::Team => 'Asignado al equipo',
            AssigneeType::Queue => 'Asignado a la cola del equipo',
            AssigneeType::AutomatedHandler => 'Asignado a atención automática',
        };
    }

    private function guardAssignee(Incident $incident, AssigneeType $assigneeType, int $assigneeId): void
    {
        $teamId = $incident->team_id;

        $valid = match ($assigneeType) {
            AssigneeType::User => TeamMembers::isAssignable($teamId, $assigneeId),
            AssigneeType::Team, AssigneeType::Queue => $assigneeId === $teamId,
            AssigneeType::AutomatedHandler => true,
        };

        if (! $valid) {
            throw new \InvalidArgumentException(
                "Assignee {$assigneeType->value} #{$assigneeId} does not belong to team {$teamId}."
            );
        }
    }
}
