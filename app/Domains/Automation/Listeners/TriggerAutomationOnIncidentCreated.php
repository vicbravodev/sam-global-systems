<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;

/**
 * SPEC-11-DEFERRED: bound to `App\Domains\Incidents\Events\IncidentCreated` by
 * string in `AutomationServiceProvider::boot()`.
 */
class TriggerAutomationOnIncidentCreated
{
    public function __construct(
        private TriggerEscalationWorkflow $triggerEscalationWorkflow,
    ) {}

    public function handle(object $event): void
    {
        $teamId = (int) ($event->teamId ?? 0);

        if ($teamId === 0) {
            return;
        }

        $incidentId = $event->incidentId ?? null;
        $payload = (array) ($event->payload ?? []);

        $this->triggerEscalationWorkflow->execute(
            teamId: $teamId,
            triggerType: WorkflowTriggerType::IncidentCreated,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: $incidentId !== null ? (string) $incidentId : null,
            payload: $payload,
        );
    }
}
