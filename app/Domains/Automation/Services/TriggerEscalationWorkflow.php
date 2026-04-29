<?php

namespace App\Domains\Automation\Services;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Automation\Models\AutomationWorkflow;

class TriggerEscalationWorkflow
{
    /**
     * Find matching active workflows and dispatch a RunAutomationWorkflowJob per match.
     *
     * @param  array<string, mixed>  $payload  Source event payload used to evaluate trigger_conditions_json.
     * @return array<int, int> IDs of the workflows that matched and were dispatched.
     */
    public function execute(
        int $teamId,
        WorkflowTriggerType $triggerType,
        ActionExecutionSourceType $sourceType,
        ?string $sourceReferenceId,
        array $payload = [],
    ): array {
        $workflows = AutomationWorkflow::query()
            ->availableToTeam($teamId)
            ->active()
            ->where('trigger_type', $triggerType)
            ->get();

        $dispatched = [];

        foreach ($workflows as $workflow) {
            if (! $this->conditionsMatch($workflow->trigger_conditions_json ?? [], $payload)) {
                continue;
            }

            RunAutomationWorkflowJob::dispatch(
                $workflow->id,
                $teamId,
                $sourceType->value,
                $sourceReferenceId,
            );

            $dispatched[] = $workflow->id;
        }

        return $dispatched;
    }

    /**
     * Trigger conditions are evaluated as a flat AND of equality checks against the payload.
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $payload
     */
    private function conditionsMatch(array $conditions, array $payload): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $key => $expected) {
            $actual = $payload[$key] ?? null;
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }
}
