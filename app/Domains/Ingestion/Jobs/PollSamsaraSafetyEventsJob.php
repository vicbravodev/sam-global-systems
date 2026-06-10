<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled orchestrator: fans out a {@see PollSafetyEventsJob} for every
 * active Samsara integration whose safety-event feed polling is enabled.
 *
 * Runs across all tenants (global scope bypassed) since the scheduler has no
 * tenant context — the same pattern as PollAllAssetLocationsJob.
 */
class PollSamsaraSafetyEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(): void
    {
        TenantIntegration::withoutGlobalScopes()
            ->where('status', TenantIntegrationStatus::Active)
            ->with('provider')
            ->each(function (TenantIntegration $integration): void {
                if ($this->shouldPoll($integration)) {
                    PollSafetyEventsJob::dispatch($integration);
                }
            });
    }

    private function shouldPoll(TenantIntegration $integration): bool
    {
        if ($integration->provider?->code !== 'samsara') {
            return false;
        }

        $sync = $integration->config_json['sync'] ?? [];

        if (($sync['enabled'] ?? true) === false) {
            return false;
        }

        return ($sync['poll_safety_events'] ?? true) !== false;
    }
}
