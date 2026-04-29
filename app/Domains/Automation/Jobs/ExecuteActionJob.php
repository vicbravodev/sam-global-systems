<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Models\ActionExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            return;
        }

        if (in_array($execution->status, [
            ActionExecutionStatus::Completed,
            ActionExecutionStatus::Cancelled,
        ], true)) {
            return;
        }

        $executeAction->execute($execution);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ExecuteActionJob failed', [
            'action_execution_id' => $this->actionExecutionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
