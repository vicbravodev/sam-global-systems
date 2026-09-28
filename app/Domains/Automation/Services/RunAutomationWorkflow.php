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
use App\Domains\Incidents\Models\Incident;
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
        $existing = WorkflowExecution::query()
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

            $incidentId = $this->linkedIncidentId($teamId, $sourceType, $sourceReferenceId);

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
                    incidentId: $incidentId,
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
        ?int $incidentId,
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
                'incident_id' => $incidentId,
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

    /**
     * Incidente sobre el que corre el workflow, cuando lo disparó un
     * incidente (creado o escalado). Cada paso lo hereda en `incident_id`:
     * sin él, asignar/escalar/pedir revisión fallaban con "requires a linked
     * incident" (el paso nace con source_type=workflow) y las plantillas no
     * recibían `{{incident.*}}`. El id se verifica contra el team (§2.1.4).
     */
    private function linkedIncidentId(int $teamId, ActionExecutionSourceType $sourceType, ?string $sourceReferenceId): ?int
    {
        if (! in_array($sourceType, [ActionExecutionSourceType::Incident, ActionExecutionSourceType::Escalation], true)
            || $sourceReferenceId === null
            || ! ctype_digit($sourceReferenceId)) {
            return null;
        }

        $incidentId = (int) $sourceReferenceId;

        return Incident::query()
            ->whereKey($incidentId)
            ->where('team_id', $teamId)
            ->exists() ? $incidentId : null;
    }
}
