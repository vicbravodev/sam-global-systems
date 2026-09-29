<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AutoAssignIncidentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $incidentId,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(AssignIncident $assignIncident): void
    {
        $incident = Incident::withoutGlobalScopes()->find($this->incidentId);
        $input = ['incident_id' => $this->incidentId, 'stage' => 'auto_assign_job'];

        if ($incident === null) {
            SystemLog::skipped('incidents.assignment.resolved', reason: 'incident_missing', input: $input);

            return;
        }

        PipelineTrace::adopt(null, $incident->team_id, ['incident_id' => $incident->id]);

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($incident->team_id);

        // A deduped event (or a B8 re-decision) re-dispatches this job for an
        // incident that is already routed — re-assigning would spam the
        // timeline and steal assignments operators already took.
        $alreadyAssigned = IncidentAssignment::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unassigned_at')
            ->exists();

        if ($alreadyAssigned) {
            SystemLog::skipped('incidents.assignment.resolved', reason: 'already_assigned', input: $input);

            return;
        }

        // Default fallback assignment: route to the team's default queue.
        // Tenant-specific assignment rules will hook in via spec 16 (TenantConfig).
        $assignment = $assignIncident->execute(
            incident: $incident,
            assigneeType: AssigneeType::Queue,
            assigneeId: $incident->team_id,
            role: 'default',
        );

        SystemLog::ok('incidents.assignment.resolved',
            input: $input,
            calc: ['source' => 'default_queue'],
            result: [
                'assignee_type' => AssigneeType::Queue->value,
                'assignment_id' => $assignment->id,
                'role' => 'default',
            ],
        );
    }
}
