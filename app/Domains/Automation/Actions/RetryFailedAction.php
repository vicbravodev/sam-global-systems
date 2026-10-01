<?php

namespace App\Domains\Automation\Actions;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Support\SystemLog;

class RetryFailedAction
{
    public function __construct(
        private TenantAutomationPoliciesResolver $policiesResolver,
    ) {}

    /**
     * Re-queue a failed execution if retries remain. Returns true when re-queued,
     * false when retries are exhausted (the record stays in `failed`).
     */
    public function execute(ActionExecution $execution): bool
    {
        $policies = $this->policiesResolver->resolve($execution->team_id);

        if ($execution->attempts >= $policies->maxRetries) {
            SystemLog::skipped('automation.action.retry_scheduled', reason: 'retries_exhausted', input: ['action_execution_id' => $execution->id], calc: ['attempts' => $execution->attempts, 'max_retries' => $policies->maxRetries]);

            return false;
        }

        $backoff = $policies->retryBackoffSeconds;
        // Sin backoff configurado, end() devuelve false: se reintenta ya.
        $delaySeconds = $backoff[$execution->attempts] ?? end($backoff);
        $delaySeconds = $delaySeconds === false ? 0 : $delaySeconds;

        $execution->status = ActionExecutionStatus::Retrying;
        $execution->save();

        ActionExecutionLog::create([
            'action_execution_id' => $execution->id,
            'log_type' => ActionLogType::Retry,
            'message' => "Retrying action (attempt {$execution->attempts} / {$policies->maxRetries}).",
            'payload_json' => ['delay_seconds' => $delaySeconds],
        ]);

        $pending = ExecuteActionJob::dispatch($execution->id);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }

        // El PendingDispatch encola al destruirse: se encola antes de registrarlo.
        unset($pending);

        // delay_seconds = (backoff[attempts] ?? last(backoff)) ?: 0
        SystemLog::ok(
            'automation.action.retry_scheduled',
            input: ['action_execution_id' => $execution->id],
            calc: [
                'attempts' => $execution->attempts,
                'max_retries' => $policies->maxRetries,
                'backoff_schedule_seconds' => $backoff,
                'backoff_index' => $execution->attempts,
                'index_in_schedule' => array_key_exists($execution->attempts, $backoff),
            ],
            result: ['delay_seconds' => $delaySeconds, 'job_requested' => true],
        );

        return true;
    }
}
