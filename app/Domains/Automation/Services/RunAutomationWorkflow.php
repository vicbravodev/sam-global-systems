<?php

namespace App\Domains\Automation\Services;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Actions\ResolveActionTemplate;
use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Enums\WorkflowExecutionStatus;
use App\Domains\Automation\Events\WorkflowCompleted;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Support\Facades\DB;

class RunAutomationWorkflow
{
    public function __construct(
        private ResolveActionTemplate $resolveActionTemplate,
        private TenantAutomationPoliciesResolver $policiesResolver,
        private RecordUsageEvent $recordUsageEvent,
    ) {}

    /**
     * Idempotent. Creates a WorkflowExecution and an ActionExecution per step,
     * dispatching ExecuteActionJob with the proper delay for each.
     *
     * @return WorkflowExecution|null Null when an execution already exists for the same source.
     */
    public function execute(
        AutomationWorkflow $workflow,
        int $teamId,
        ActionExecutionSourceType $sourceType,
        ?string $sourceReferenceId,
    ): ?WorkflowExecution {
        $existing = WorkflowExecution::withoutGlobalScopes()
            ->where('automation_workflow_id', $workflow->id)
            ->where('source_type', $sourceType->value)
            ->when(
                $sourceReferenceId !== null,
                fn ($q) => $q->where('source_reference_id', $sourceReferenceId),
                fn ($q) => $q->whereNull('source_reference_id'),
            )
            ->first();

        if ($existing !== null) {
            return null;
        }

        return DB::transaction(function () use ($workflow, $teamId, $sourceType, $sourceReferenceId) {
            $workflowExecution = WorkflowExecution::create([
                'team_id' => $teamId,
                'automation_workflow_id' => $workflow->id,
                'source_type' => $sourceType->value,
                'source_reference_id' => $sourceReferenceId,
                'status' => WorkflowExecutionStatus::Running,
                'started_at' => now(),
            ]);

            $this->recordUsageEvent->execute(
                teamId: $teamId,
                meterCode: 'incident_workflows',
                quantity: 1,
                eventKey: "workflow_exec_{$workflowExecution->id}",
            );

            $steps = $workflow->steps_json ?? [];
            $hasSteps = false;
            $cumulativeDelay = 0;

            foreach ($steps as $step) {
                $hasSteps = true;
                $cumulativeDelay += (int) ($step['delay_seconds'] ?? 0);
                $this->dispatchStep(
                    workflow: $workflow,
                    workflowExecution: $workflowExecution,
                    step: $step,
                    cumulativeDelay: $cumulativeDelay,
                );
            }

            if (! $hasSteps) {
                $workflowExecution->update([
                    'status' => WorkflowExecutionStatus::Completed,
                    'completed_at' => now(),
                ]);
                WorkflowCompleted::dispatch($workflowExecution);
            }

            return $workflowExecution;
        });
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function dispatchStep(
        AutomationWorkflow $workflow,
        WorkflowExecution $workflowExecution,
        array $step,
        int $cumulativeDelay,
    ): void {
        $actionType = ActionType::from((string) $step['action_type']);
        $executionMode = ExecutionMode::from((string) ($step['execution_mode'] ?? 'async'));
        $targetType = $step['target_type'] ?? null;
        $targetReference = $step['target_reference'] ?? null;
        $templateCode = $step['template_code'] ?? null;

        $template = is_string($templateCode) && $templateCode !== ''
            ? $this->resolveActionTemplate->execute($workflow->team_id ?? $workflowExecution->team_id, $templateCode)
            : null;

        $payload = array_merge(
            ['step_order' => $step['order'] ?? null],
            (array) ($step['payload'] ?? []),
        );

        $execution = ActionExecution::firstOrCreate(
            [
                'team_id' => $workflowExecution->team_id,
                'source_type' => ActionExecutionSourceType::Workflow->value,
                'source_reference_id' => (string) $workflowExecution->id,
                'action_type' => $actionType->value,
                'target_reference' => $targetReference,
            ],
            [
                'automation_workflow_id' => $workflow->id,
                'action_template_id' => $template?->id,
                'status' => $executionMode === ExecutionMode::RequiresConfirmation
                    ? ActionExecutionStatus::Pending
                    : ActionExecutionStatus::Queued,
                'execution_mode' => $executionMode->value,
                'target_type' => $targetType,
                'payload_json' => $payload,
                'attempts' => 0,
            ],
        );

        if ($executionMode === ExecutionMode::RequiresConfirmation) {
            return;
        }

        $job = ExecuteActionJob::dispatch($execution->id);

        if ($cumulativeDelay > 0) {
            $job->delay(now()->addSeconds($cumulativeDelay));
        }
    }
}
