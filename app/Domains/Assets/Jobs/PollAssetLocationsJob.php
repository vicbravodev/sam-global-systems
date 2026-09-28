<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\ResolveAssetsFromExternalIds;
use App\Domains\Assets\Actions\UpdateAssetLocationSnapshot;
use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
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
 * Runs every minute, so the per-vehicle cost is kept flat: the whole batch is
 * resolved to assets in two queries, and the fixes already stored (a parked
 * vehicle reports the same one poll after poll) are filtered out with a single
 * lookup, so only vehicles that actually moved reach the write path.
 *
 * Unique per integration so overlapping ticks never double-poll the provider.
 */
class PollAssetLocationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries: the next tick is a minute away and re-reads the latest fix,
     * while a delayed retry would replay an older position over a newer one.
     */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Releases the lock if a worker dies mid-poll, so a crash can never stall
     * the positions of a whole tenant until someone clears the cache.
     */
    public int $uniqueFor = 180;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        ResolveAssetsFromExternalIds $resolveAssets,
        UpdateAssetLocationSnapshot $updateLocation,
    ): void {
        // Stamped with the start of the poll, not its end: the orchestrator
        // measures the interval from here, and a slow poll must not push the
        // next one a whole tick further out.
        $startedAt = now();

        $fixes = [];

        foreach ($providerAdapter->fetchAssetLocations($this->integration) as $location) {
            $externalId = (string) ($location['external_id'] ?? '');

            if ($externalId === '') {
                continue;
            }

            $fixes[$externalId] = $location;
        }

        // The resolver is tenant-scoped on purpose: (provider, external_id)
        // is unique platform-wide, so without the team filter a poll could
        // land on another tenant's asset.
        $assets = $resolveAssets->execute(
            $this->integration->provider_id,
            array_map('strval', array_keys($fixes)),
            $this->integration->team_id,
        );

        $recordedAts = [];

        foreach ($assets as $externalId => $asset) {
            $recordedAt = $fixes[$externalId]['recorded_at'] ?? null;
            $recordedAts[$externalId] = $recordedAt !== null ? Carbon::parse($recordedAt) : null;
        }

        $stored = $this->storedFixes($assets, $recordedAts);

        foreach ($assets as $externalId => $asset) {
            $location = $fixes[$externalId];
            $recordedAt = $recordedAts[$externalId];

            if ($recordedAt !== null && isset($stored[$this->fixKey($asset->id, $recordedAt)])) {
                continue;
            }

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

        $this->integration->update(['last_location_poll_at' => $startedAt]);
    }

    /**
     * The fixes of this batch that are already stored, as a set of fix keys.
     *
     * @param  array<string, Asset>  $assets
     * @param  array<string, Carbon|null>  $recordedAts
     * @return array<string, true>
     */
    private function storedFixes(array $assets, array $recordedAts): array
    {
        $times = array_values(array_filter($recordedAts));

        if ($times === []) {
            return [];
        }

        $assetIds = array_map(fn ($asset) => $asset->id, array_values($assets));

        // Both lists are bounded by the fleet size, and the (asset_id,
        // recorded_at) index serves the lookup; the cross product it can match
        // is narrowed back to exact pairs by the key set below.
        return AssetLocationSnapshot::query()
            ->whereIn('asset_id', $assetIds)
            ->whereIn('recorded_at', array_unique(array_map(fn (Carbon $time) => $time->copy()->utc()->format('Y-m-d H:i:s'), $times)))
            ->get(['asset_id', 'recorded_at'])
            ->mapWithKeys(fn (AssetLocationSnapshot $snapshot) => [$this->fixKey($snapshot->asset_id, $snapshot->recorded_at) => true])
            ->all();
    }

    private function fixKey(int $assetId, \DateTimeInterface $recordedAt): string
    {
        return $assetId.'@'.Carbon::instance($recordedAt)->utc()->format('Y-m-d H:i:s');
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
