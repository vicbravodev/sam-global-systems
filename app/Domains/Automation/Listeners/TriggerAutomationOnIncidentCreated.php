<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;
use App\Domains\Incidents\Events\IncidentCreated;

class TriggerAutomationOnIncidentCreated
{
    public function __construct(
        private TriggerEscalationWorkflow $triggerEscalationWorkflow,
    ) {}

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        $this->triggerEscalationWorkflow->execute(
            teamId: (int) $incident->team_id,
            triggerType: WorkflowTriggerType::IncidentCreated,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            payload: [
                'incident_id' => $incident->id,
                'incident_type' => $incident->type?->code,
                'severity' => $incident->priority?->code,
            ],
        );
    }
}
