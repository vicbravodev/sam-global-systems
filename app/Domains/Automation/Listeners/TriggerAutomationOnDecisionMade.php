<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;
use App\Domains\Decisions\Events\DecisionMade;

class TriggerAutomationOnDecisionMade
{
    public function __construct(
        private TriggerEscalationWorkflow $triggerEscalationWorkflow,
    ) {}

    public function handle(DecisionMade $event): void
    {
        $decision = $event->decision;

        if ($decision->team_id === null) {
            return;
        }

        $this->triggerEscalationWorkflow->execute(
            teamId: (int) $decision->team_id,
            triggerType: WorkflowTriggerType::DecisionOutcome,
            sourceType: ActionExecutionSourceType::Decision,
            sourceReferenceId: (string) $decision->id,
            payload: [
                'decision_id' => $decision->id,
                'decision_code' => $decision->decision_code,
                'priority_level' => $decision->priority_level?->value,
                'outcome' => $decision->outcome?->code,
            ],
        );
    }
}
