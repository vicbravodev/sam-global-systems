<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $actionExecutionId,
    ) {
        $this->onQueue('automation');
    }

    public function handle(ExecuteAction $executeAction): void
    {
        $execution = ActionExecution::withoutGlobalScopes()->find($this->actionExecutionId);

        if ($execution === null) {
            SystemLog::skipped('automation.action.skipped', reason: 'execution_missing', input: ['action_execution_id' => $this->actionExecutionId]);

            return;
        }

        PipelineTrace::adopt(null, $execution->team_id, [
            'incident_id' => $execution->incident_id,
            'decision_id' => $execution->decision_id,
            'action_execution_id' => $execution->id,
        ]);

        if (in_array($execution->status, [
            ActionExecutionStatus::Completed,
            ActionExecutionStatus::Cancelled,
        ], true)) {
            SystemLog::skipped('automation.action.skipped', reason: 'already_'.$execution->status->value, input: $this->logInput($execution));

            return;
        }

        // Entra en el tenant de la ejecución: la acción resuelve plantillas,
        // canales y destinatarios del tenant. Ver §2.1.
        TenantContext::for($execution->team_id, function () use ($execution, $executeAction) {
            $stop = $this->incidentStopReason($execution);

            if ($stop !== null) {
                $executeAction->cancel($execution, $stop['message']);

                SystemLog::skipped('automation.action.stopped', reason: $stop['code'], input: $this->logInput($execution), calc: ['incident_id' => $stop['incident_id'], 'delayed' => true]);

                return;
            }

            $executeAction->execute($execution);
        });
    }

    /**
     * Un paso con retraso (delay_seconds) se programó cuando el incidente
     * estaba abierto y sin dueño. Al llegar su turno se revalida: si el
     * incidente ya se cerró o un humano lo tomó / acusó recibo, el paso se
     * cancela en vez de mandar un SMS o escalar a destiempo.
     *
     * Las ejecuciones confirmadas por un humano (`requires_confirmation`) y
     * las manuales no se revalidan: las pidió una persona.
     *
     * @return array{code: 'incident_terminal'|'human_control', incident_id: int, message: string}|null
     */
    private function incidentStopReason(ActionExecution $execution): ?array
    {
        if ($execution->execution_mode === ExecutionMode::RequiresConfirmation
            || $execution->source_type === ActionExecutionSourceType::Manual) {
            return null;
        }

        $incidentId = $this->linkedIncidentId($execution);

        if ($incidentId === null) {
            return null;
        }

        $incident = Incident::query()
            ->whereKey($incidentId)
            ->where('team_id', $execution->team_id)
            ->with('status')
            ->first();

        if ($incident === null) {
            return null;
        }

        if ($incident->isTerminal()) {
            return ['code' => 'incident_terminal', 'incident_id' => $incident->id, 'message' => "Incident {$incident->id} is already closed; delayed step cancelled."];
        }

        if (IncidentSuppression::isUnderHumanControl($incident)) {
            return ['code' => 'human_control', 'incident_id' => $incident->id, 'message' => "Incident {$incident->id} is under human control; delayed step cancelled."];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function logInput(ActionExecution $execution): array
    {
        return [
            'action_execution_id' => $execution->id,
            'action_type' => $execution->action_type->value,
            'execution_mode' => $execution->execution_mode?->value,
            'source_type' => $execution->source_type?->value,
        ];
    }

    private function linkedIncidentId(ActionExecution $execution): ?int
    {
        if ($execution->incident_id !== null) {
            return (int) $execution->incident_id;
        }

        $sourceType = $execution->source_type;
        $reference = $execution->source_reference_id;

        if (in_array($sourceType, [ActionExecutionSourceType::Incident, ActionExecutionSourceType::Escalation], true)) {
            return is_numeric($reference) ? (int) $reference : null;
        }

        if ($sourceType !== ActionExecutionSourceType::Workflow || ! is_numeric($reference)) {
            return null;
        }

        $workflowExecution = WorkflowExecution::query()
            ->whereKey((int) $reference)
            ->where('team_id', $execution->team_id)
            ->first();

        if ($workflowExecution === null
            || ! in_array($workflowExecution->source_type, [ActionExecutionSourceType::Incident->value, ActionExecutionSourceType::Escalation->value], true)
            || ! is_numeric($workflowExecution->source_reference_id)) {
            return null;
        }

        return (int) $workflowExecution->source_reference_id;
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['action_execution_id' => $this->actionExecutionId]);
    }
}
