<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
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

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ProviderAdapter $providerAdapter, ResolveAssetFromExternalId $resolveAsset): void
    {
        $readings = $providerAdapter->fetchDeviceConnectivity($this->integration);
        $polledAt = now();

        foreach ($readings as $reading) {
            $externalId = $reading['external_id'] ?? null;

            if ($externalId === null || $externalId === '') {
                continue;
            }

            // Tenant-scoped on purpose: (provider, external_id) is unique
            // platform-wide, so without the team filter a poll could land on
            // another tenant's asset.
            $asset = $resolveAsset->execute(
                $this->integration->provider_id,
                (string) $externalId,
                $this->integration->team_id,
            );

            if ($asset === null) {
                continue;
            }

            $asset->forceFill([
                'device_last_connected_at' => isset($reading['last_connected_at'])
                    ? Carbon::parse($reading['last_connected_at'])
                    : null,
                'device_health_status' => $reading['health_status'] ?? null,
                'device_connectivity_polled_at' => $polledAt,
            ])->save();
        }
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
