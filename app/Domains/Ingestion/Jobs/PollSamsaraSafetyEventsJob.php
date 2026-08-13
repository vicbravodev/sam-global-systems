<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\TenantContext;
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
        // Fan-out de plataforma: recorre todos los tenants a propósito, y
        // mete cada iteración en el contexto de SU tenant para que todo lo
        // que se despache desde ahí viaje ya scopeado. Ver §2.1.
        TenantContext::withoutTenant(fn () => TenantIntegration::query()
            ->where('status', TenantIntegrationStatus::Active)
            ->with('provider')
            ->each(fn (TenantIntegration $integration) => TenantContext::for($integration->team_id, function () use ($integration): void {
                if ($this->shouldPoll($integration)) {
                    PollSafetyEventsJob::dispatch($integration);
                }
            })));
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
