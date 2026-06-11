<?php

namespace App\Domains\Incidents\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Models\IncidentCallVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Safety net for the verification call (Roadmap V2-A3): if neither the DTMF
 * gather nor Twilio's status callback resolved the attempt by the time the
 * retry delay elapses, treat it as unanswered so the chain keeps moving.
 *
 * Early deliveries (sync queues, redeliveries) no-op instead of failing the
 * attempt before the operator had a chance to pick up — same guard the SLA
 * watchdog uses.
 */
class EvaluateVerificationCallOutcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $verificationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        HandleVerificationCallAttemptFailure $handleFailure,
        TenantConfigResolver $tenantConfig,
    ): void {
        $verification = IncidentCallVerification::withoutGlobalScopes()->find($this->verificationId);

        if ($verification === null || ! $verification->status->isInFlight()) {
            return;
        }

        $retryDelay = max(30, (int) $tenantConfig->resolve(
            (int) $verification->team_id,
            StartIncidentCallVerification::SETTING_RETRY_DELAY,
            StartIncidentCallVerification::DEFAULT_RETRY_DELAY_SECONDS,
        ));

        if ($verification->placed_at !== null && $verification->placed_at->copy()->addSeconds($retryDelay)->isFuture()) {
            return;
        }

        $handleFailure->execute($verification, 'timeout_without_callback');
    }
}
