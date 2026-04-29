<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Models\Incident;
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

        if ($incident === null) {
            return;
        }

        // Default fallback assignment: route to the team's default queue.
        // Tenant-specific assignment rules will hook in via spec 16 (TenantConfig).
        $assignIncident->execute(
            incident: $incident,
            assigneeType: AssigneeType::Queue,
            assigneeId: $incident->team_id,
            role: 'default',
        );
    }
}
