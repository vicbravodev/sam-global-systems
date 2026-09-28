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
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled orchestrator: fans out a {@see PollAssetLocationsJob} for every
 * active integration that is due for a position refresh.
 *
 * Runs across all tenants (global scope bypassed) since the scheduler has no
 * tenant context. Per-integration cadence is read from config_json.sync and
 * gated against last_location_poll_at so a fast scheduler tick never polls a
 * provider more often than its configured interval.
 *
 * The default is one minute, the scheduler's own floor: positions feed the
 * live map and the movement detectors, so they are refreshed as often as the
 * platform ticks. Device connectivity rides along on a slower, separate gate —
 * the offline watchdog that reads it only ticks every five minutes.
 */
class PollAllAssetLocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DEFAULT_INTERVAL_MINUTES = 1;

    /**
     * Matches the cadence of {@see DetectOfflineAssetsJob}, the only reader of
     * the device heartbeat; polling `/gateways` faster buys it nothing.
     */
    public const CONNECTIVITY_INTERVAL_MINUTES = 5;

    /**
     * Slack on the due check. The poll stamps the moment it starts, which is a
     * few seconds after the tick that dispatched it; without this margin the
     * next tick, exactly one interval later, would find it "not yet due" and a
     * one-minute interval would silently become two.
     */
    public const DUE_GRACE_SECONDS = 15;

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
            ->ofLiveTeam()
            ->with('provider')
            ->each(fn (TenantIntegration $integration) => TenantContext::for($integration->team_id, function () use ($integration): void {
                if (! $this->isDue($integration)) {
                    return;
                }

                PollAssetLocationsJob::dispatch($integration);

                if ($this->connectivityIsDue($integration)) {
                    PollAssetConnectivityJob::dispatch($integration);
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

        return $lastPoll === null
            || $lastPoll->lte(now()->subSeconds($interval * 60 - self::DUE_GRACE_SECONDS));
    }

    /**
     * Claims the connectivity slot of this integration for the next interval.
     * `Cache::add` is atomic, so only the first tick of each window dispatches.
     */
    private function connectivityIsDue(TenantIntegration $integration): bool
    {
        return Cache::add(
            "poll-connectivity-due:{$integration->id}",
            true,
            now()->addSeconds(self::CONNECTIVITY_INTERVAL_MINUTES * 60 - self::DUE_GRACE_SECONDS),
        );
    }
}
