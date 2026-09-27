<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled orchestrator: fans out a {@see PollAssetLocationsJob} for every
 * active integration that is due for a position refresh.
 *
 * Runs across all tenants (global scope bypassed) since the scheduler has no
 * tenant context. Per-integration cadence is read from config_json.sync and
 * gated against last_location_poll_at so a fast scheduler tick never polls a
 * provider more often than its configured interval.
 */
class PollAllAssetLocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DEFAULT_INTERVAL_MINUTES = 5;

    public function __construct()
    {
        $this->onQueue('sync');
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
                if ($this->isDue($integration)) {
                    PollAssetLocationsJob::dispatch($integration);
                }
            })));
    }

    private function isDue(TenantIntegration $integration): bool
    {
        $sync = $integration->config_json['sync'] ?? [];

        if (($sync['enabled'] ?? true) === false) {
            return false;
        }

        if (($sync['poll_locations'] ?? true) === false) {
            return false;
        }

        $interval = max(1, (int) ($sync['location_interval_minutes'] ?? self::DEFAULT_INTERVAL_MINUTES));
        $lastPoll = $integration->last_location_poll_at;

        return $lastPoll === null || $lastPoll->lte(now()->subMinutes($interval));
    }
}
