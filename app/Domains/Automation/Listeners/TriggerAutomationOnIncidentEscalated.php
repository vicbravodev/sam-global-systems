<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;
use App\Domains\Incidents\Events\IncidentStatusChanged;

class TriggerAutomationOnIncidentEscalated
{
    public function __construct(
        private TriggerEscalationWorkflow $triggerEscalationWorkflow,
    ) {}

    public function handle(IncidentStatusChanged $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        $this->triggerEscalationWorkflow->execute(
            teamId: (int) $incident->team_id,
            triggerType: WorkflowTriggerType::IncidentEscalated,
            sourceType: ActionExecutionSourceType::Escalation,
            sourceReferenceId: (string) $incident->id,
            payload: [
                'incident_id' => $incident->id,
                'previous_status' => $event->previousStatus,
                'new_status' => $event->newStatus,
            ],
        );
    }
}
