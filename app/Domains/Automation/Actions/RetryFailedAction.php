<?php

namespace App\Domains\Automation\Actions;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;

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
            return false;
        }

        $backoff = $policies->retryBackoffSeconds;
        $delaySeconds = $backoff[$execution->attempts] ?? end($backoff) ?: 0;

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

        return true;
    }
}
