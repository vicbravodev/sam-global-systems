<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;

/**
 * SPEC-10-DEFERRED: bound to `App\Domains\Decisions\Events\DecisionMade` by string
 * in `AutomationServiceProvider::boot()`. The Decisions domain ships its event class
 * in spec 10; this listener reads payload fields generically so it works against
 * any object exposing teamId/decisionId/payload properties.
 */
class TriggerAutomationOnDecisionMade
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

        $decisionId = $event->decisionId ?? null;
        $payload = (array) ($event->payload ?? []);

        $this->triggerEscalationWorkflow->execute(
            teamId: $teamId,
            triggerType: WorkflowTriggerType::DecisionOutcome,
            sourceType: ActionExecutionSourceType::Decision,
            sourceReferenceId: $decisionId !== null ? (string) $decisionId : null,
            payload: $payload,
        );
    }
}
