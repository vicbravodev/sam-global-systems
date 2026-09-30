<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\ResolveAssetsFromExternalIds;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Poll the real connectivity of every asset's telematics device for a single
 * integration and store it on the asset (`device_last_connected_at`,
 * `device_health_status`). This — not the last GPS fix — is what
 * {@see DetectOfflineAssetsJob} watches: a parked vehicle stops producing GPS
 * fixes but its gateway stays connected.
 *
 * `device_connectivity_polled_at` is stamped for every asset the provider
 * reported, so the watchdog can tell "device silent" apart from "we have not
 * heard from the provider" (API outage, revoked token, unpaired gateway).
 *
 * Unique per integration so overlapping ticks never double-poll the provider.
 */
class PollAssetConnectivityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 600;

    /** Releases the lock if a worker dies mid-poll. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ProviderAdapter $providerAdapter, ResolveAssetsFromExternalIds $resolveAssets): void
    {
        $readings = [];

        foreach ($providerAdapter->fetchDeviceConnectivity($this->integration) as $reading) {
            $externalId = (string) ($reading['external_id'] ?? '');

            if ($externalId !== '') {
                $readings[$externalId] = $reading;
            }
        }

        $polledAt = now();

        // Tenant-scoped on purpose: (provider, external_id) is unique
        // platform-wide, so without the team filter a poll could land on
        // another tenant's asset.
        $assets = $resolveAssets->execute(
            $this->integration->provider_id,
            array_map('strval', array_keys($readings)),
            $this->integration->team_id,
        );

        $withoutHeartbeat = 0;

        foreach ($assets as $externalId => $asset) {
            $reading = $readings[$externalId];

            if (! isset($reading['last_connected_at'])) {
                $withoutHeartbeat++;
            }

            $asset->forceFill([
                'device_last_connected_at' => isset($reading['last_connected_at'])
                    ? Carbon::parse($reading['last_connected_at'])
                    : null,
                'device_health_status' => $reading['health_status'] ?? null,
                'device_connectivity_polled_at' => $polledAt,
            ])->save();
        }

        // Nunca el `health_status` crudo del proveedor: sólo conteos.
        TenantContext::for($this->integration->team_id, fn () => SystemLog::ok('assets.connectivity.polled', input: [
            'team_id' => (int) $this->integration->team_id,
            'integration_id' => $this->integration->id,
        ], result: [
            'readings_reported_count' => count($readings),
            'assets_matched_count' => count($assets),
            'readings_unmatched_count' => count($readings) - count($assets),
            'without_heartbeat_count' => $withoutHeartbeat,
        ]));
    }

    public function failed(\Throwable $exception): void
    {
        $this->integration->update([
            'last_error_at' => now(),
            'last_error_message' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "poll-connectivity-{$this->integration->id}";
    }
}
