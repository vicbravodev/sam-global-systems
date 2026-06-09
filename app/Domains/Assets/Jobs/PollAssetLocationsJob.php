<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Assets\Actions\UpdateAssetLocationSnapshot;
use App\Domains\Assets\Enums\LocationSource;
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
 * Poll the latest asset positions for a single integration and persist them as
 * location snapshots. Unknown assets (no external reference yet) are skipped —
 * the catalog sync creates them and the next poll picks up their location.
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

        $this->integration->update(['last_location_poll_at' => now()]);
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
