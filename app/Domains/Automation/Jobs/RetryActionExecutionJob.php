<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Actions\RetryFailedAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Models\ActionExecution;
use App\Support\TenantContext;
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

        // Barrido de plataforma: recorre todos los tenants a propósito, pero
        // reintenta cada ejecución dentro del contexto del suyo. Ver §2.1.
        TenantContext::withoutTenant(fn () => ActionExecution::query()
            ->where('status', ActionExecutionStatus::Failed)
            ->whereNotNull('team_id')
            ->orderBy('id')
            ->chunkById(50, function ($executions) use ($retryFailedAction): void {
                foreach ($executions as $execution) {
                    TenantContext::for(
                        $execution->team_id,
                        fn () => $retryFailedAction->execute($execution),
                    );
                }
            }));
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('RetryActionExecutionJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
