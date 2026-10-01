<?php

namespace App\Domains\Automation\Services;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

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

        // Lo llaman los listeners dentro de la transacción de la decisión, la
        // creación o el escalamiento: cada línea espera al commit (un job
        // pedido en una transacción que revierte nunca sale).
        foreach ($workflows as $workflow) {
            $match = $this->conditionsMatch($workflow->trigger_conditions_json ?? [], $payload);

            $workflowInput = [
                'automation_workflow_id' => $workflow->id,
                'workflow_scope' => $workflow->team_id === null ? 'global' : 'tenant',
                'trigger_type' => $triggerType->value,
                'source_type' => $sourceType->value,
            ];

            if (! $match['matched']) {
                $mismatchCalc = [
                    'failed_key' => $match['failed_key'],
                    'expected' => $match['expected'],
                    'actual' => $match['actual'],
                    'expected_type' => $match['expected_type'],
                    'actual_type' => $match['actual_type'],
                    'conditions_count' => $match['conditions_count'],
                ];

                DB::afterCommit(fn () => SystemLog::skipped('automation.workflow.not_matched', reason: 'condition_mismatch', input: $workflowInput, calc: $mismatchCalc, debug: true));

                continue;
            }

            RunAutomationWorkflowJob::dispatch(
                $workflow->id,
                $teamId,
                $sourceType->value,
                $sourceReferenceId,
            );

            $dispatched[] = $workflow->id;

            $conditionsCount = $match['conditions_count'];

            DB::afterCommit(fn () => SystemLog::ok('automation.workflow.matched', input: $workflowInput, calc: ['conditions_count' => $conditionsCount], result: ['job_requested' => true]));
        }

        $evaluatedInput = [
            'trigger_type' => $triggerType->value,
            'source_type' => $sourceType->value,
            'source_reference_id' => LoggableCode::guard($sourceReferenceId),
        ];
        $candidatesCount = $workflows->count();
        $matchedIds = $dispatched;

        DB::afterCommit(fn () => SystemLog::ok(
            'automation.trigger.evaluated',
            input: $evaluatedInput,
            calc: ['candidates_count' => $candidatesCount],
            result: ['matched_workflow_ids' => $matchedIds, 'matched_count' => count($matchedIds)],
            debug: $matchedIds === [],
        ));

        return $dispatched;
    }

    /**
     * Trigger conditions are evaluated as a flat AND of equality checks against the payload.
     *
     * Clave y valor esperado son texto del tenant: sólo llegan al log si
     * parecen un código (`LoggableCode`).
     *
     * @param  array<array-key, mixed>  $conditions  JSON del tenant: una clave numérica ("12") llega como int.
     * @param  array<string, mixed>  $payload
     *                                         La comparación es estricta: `expected_type`/`actual_type`
     *                                         (`get_debug_type`) explican un "12" frente a 12, que en texto se ven
     *                                         iguales.
     * @return array{matched: bool, failed_key: ?string, expected: ?string, actual: ?string, expected_type: ?string, actual_type: ?string, conditions_count: int}
     */
    private function conditionsMatch(array $conditions, array $payload): array
    {
        $conditionsCount = count($conditions);

        foreach ($conditions as $key => $expected) {
            $actual = $payload[$key] ?? null;
            if ($actual !== $expected) {
                return [
                    'matched' => false,
                    'failed_key' => LoggableCode::guard((string) $key),
                    'expected' => LoggableCode::guard(is_scalar($expected) ? (string) $expected : null),
                    'actual' => LoggableCode::guard(is_scalar($actual) ? (string) $actual : null),
                    'expected_type' => get_debug_type($expected),
                    'actual_type' => get_debug_type($actual),
                    'conditions_count' => $conditionsCount,
                ];
            }
        }

        return ['matched' => true, 'failed_key' => null, 'expected' => null, 'actual' => null, 'expected_type' => null, 'actual_type' => null, 'conditions_count' => $conditionsCount];
    }
}
