<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Services\RunAutomationWorkflow;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAutomationWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $automationWorkflowId,
        public readonly int $teamId,
        public readonly string $sourceType,
        public readonly ?string $sourceReferenceId,
    ) {
        $this->onQueue('automation');
    }

    public function handle(RunAutomationWorkflow $runAutomationWorkflow): void
    {
        PipelineTrace::adopt(null, $this->teamId);

        // El modelo no lleva el trait (puede ser global), así que el scope no
        // filtra: el workflow debe ser del tenant del job o de plataforma.
        $workflow = AutomationWorkflow::query()
            ->availableToTeam($this->teamId)
            ->find($this->automationWorkflowId);

        if ($workflow === null) {
            // Sin el id: si no es del team ni de plataforma, es de otro tenant.
            SystemLog::skipped('automation.workflow.skipped', reason: 'workflow_unavailable', input: ['source_type' => $this->sourceType]);

            return;
        }

        $runAutomationWorkflow->execute(
            workflow: $workflow,
            teamId: $this->teamId,
            sourceType: ActionExecutionSourceType::from($this->sourceType),
            sourceReferenceId: $this->sourceReferenceId,
        );
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['automation_workflow_id' => $this->automationWorkflowId]);
    }
}
