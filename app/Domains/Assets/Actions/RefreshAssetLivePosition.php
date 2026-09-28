<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Carbon\CarbonInterface;

/**
 * Keep the invariant "an asset's live position (`last_*` columns) is its
 * newest location snapshot" for writers that bypass the telematics feed —
 * the feed maintains the columns itself as it ingests.
 *
 * `fromSnapshot()` runs for every snapshot created through Eloquent (live
 * lookups for critical events, factories); `forAssets()` is for bulk inserts
 * that skip model events (seeders). Both are one conditional UPDATE per
 * asset, so an older point can never overwrite a newer position.
 */
class RefreshAssetLivePosition
{
    public function fromSnapshot(AssetLocationSnapshot $snapshot): void
    {
        $this->advance($snapshot->asset_id, $snapshot);
    }

    /**
     * @param  list<int>  $assetIds
     */
    public function forAssets(array $assetIds): void
    {
        if ($assetIds === []) {
            return;
        }

        $newest = AssetLocationSnapshot::query()
            ->selectRaw('asset_id, MAX(recorded_at) AS newest_at')
            ->whereIn('asset_id', $assetIds)
            ->groupBy('asset_id');

        AssetLocationSnapshot::query()
            ->joinSub($newest, 'newest', fn ($join) => $join
                ->on('asset_location_snapshots.asset_id', '=', 'newest.asset_id')
                ->on('asset_location_snapshots.recorded_at', '=', 'newest.newest_at'))
            ->get(['asset_location_snapshots.*'])
            ->each(fn (AssetLocationSnapshot $snapshot) => $this->advance($snapshot->asset_id, $snapshot));
    }

    private function advance(int $assetId, AssetLocationSnapshot $snapshot): void
    {
        /** @var CarbonInterface $at */
        $at = $snapshot->recorded_at;

        Asset::query()
            ->whereKey($assetId)
            ->where(fn ($query) => $query
                ->whereNull('last_location_at')
                ->orWhere('last_location_at', '<', $at))
            ->update([
                'last_latitude' => $snapshot->latitude,
                'last_longitude' => $snapshot->longitude,
                'last_speed_kph' => $snapshot->speed,
                'last_heading' => $snapshot->heading,
                'last_formatted_location' => $snapshot->formatted_location,
                'last_location_at' => $at,
            ]);
    }
}
