<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Services\RunAutomationWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        $workflow = AutomationWorkflow::query()->find($this->automationWorkflowId);

        if ($workflow === null) {
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
        Log::warning('RunAutomationWorkflowJob failed', [
            'automation_workflow_id' => $this->automationWorkflowId,
            'error' => $exception->getMessage(),
        ]);
    }
}
