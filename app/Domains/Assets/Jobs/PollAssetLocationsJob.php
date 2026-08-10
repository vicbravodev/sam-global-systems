<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Assets\Actions\UpdateAssetLocationSnapshot;
use App\Domains\Assets\Actions\UpdateAssetTelemetrySnapshot;
use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Enums\TelemetryType;
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
 * Poll the provider's latest stats for a single integration and persist them as
 * location and telemetry snapshots. Unknown assets (no external reference yet)
 * are skipped — the catalog sync creates them and the next poll picks them up.
 *
 * Both signals come from the same per-vehicle stats snapshot, so they are polled
 * together on one cadence: telemetry (fuel, odometer, ignition, battery,
 * temperature) is the only fleet-wide source there is, since provider events
 * carry no such readings. Tenants that do not want it can opt out with
 * `config_json.sync.poll_telemetry = false`.
 *
 * Unique per integration so overlapping ticks never double-poll the provider.
 */
class PollAssetLocationsJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(
        ProviderAdapter $providerAdapter,
        ResolveAssetFromExternalId $resolveAsset,
        UpdateAssetLocationSnapshot $updateLocation,
        UpdateAssetTelemetrySnapshot $updateTelemetry,
    ): void {
        $locations = $providerAdapter->fetchAssetLocations($this->integration);

        foreach ($locations as $location) {
            $externalId = $location['external_id'] ?? null;

            if ($externalId === null || $externalId === '') {
                continue;
            }

            $asset = $resolveAsset->execute($this->integration->provider_id, (string) $externalId);

            if ($asset === null) {
                continue;
            }

            $recordedAt = isset($location['recorded_at']) && $location['recorded_at'] !== null
                ? Carbon::parse($location['recorded_at'])
                : null;

            $updateLocation->execute(
                asset: $asset,
                latitude: (float) $location['latitude'],
                longitude: (float) $location['longitude'],
                source: LocationSource::Provider,
                recordedAt: $recordedAt,
                speed: isset($location['speed']) ? (float) $location['speed'] : null,
                heading: isset($location['heading']) ? (int) $location['heading'] : null,
                formattedLocation: $location['formatted_location'] ?? null,
            );
        }

        if ($this->telemetryEnabled()) {
            $this->pollTelemetry($providerAdapter, $resolveAsset, $updateTelemetry);
        }

        $this->integration->update(['last_location_poll_at' => now()]);
    }

    private function telemetryEnabled(): bool
    {
        return ($this->integration->config_json['sync']['poll_telemetry'] ?? true) !== false;
    }

    private function pollTelemetry(
        ProviderAdapter $providerAdapter,
        ResolveAssetFromExternalId $resolveAsset,
        UpdateAssetTelemetrySnapshot $updateTelemetry,
    ): void {
        foreach ($providerAdapter->fetchAssetTelemetry($this->integration) as $record) {
            $externalId = $record['external_id'] ?? null;

            if ($externalId === null || $externalId === '') {
                continue;
            }

            $asset = $resolveAsset->execute($this->integration->provider_id, (string) $externalId);

            if ($asset === null) {
                continue;
            }

            foreach ($record['readings'] ?? [] as $reading) {
                $type = TelemetryType::tryFrom((string) ($reading['type'] ?? ''));

                // A provider reporting a metric this build has no telemetry type
                // for is skipped rather than stored under a bogus type.
                if ($type === null) {
                    continue;
                }

                $updateTelemetry->execute(
                    asset: $asset,
                    type: $type,
                    data: $reading['data'] ?? [],
                    recordedAt: isset($reading['recorded_at']) && $reading['recorded_at'] !== null
                        ? Carbon::parse($reading['recorded_at'])
                        : null,
                );
            }
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
        return "poll-locations-{$this->integration->id}";
    }
}
