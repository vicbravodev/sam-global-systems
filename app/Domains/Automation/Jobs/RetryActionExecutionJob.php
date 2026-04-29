<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Actions\RetryFailedAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Models\ActionExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryActionExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct() {}

    public function handle(RetryFailedAction $retryFailedAction): void
    {
        $this->onQueue('automation');

        ActionExecution::withoutGlobalScopes()
            ->where('status', ActionExecutionStatus::Failed)
            ->whereNotNull('team_id')
            ->orderBy('id')
            ->chunkById(50, function ($executions) use ($retryFailedAction): void {
                foreach ($executions as $execution) {
                    $retryFailedAction->execute($execution);
                }
            });
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('RetryActionExecutionJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
