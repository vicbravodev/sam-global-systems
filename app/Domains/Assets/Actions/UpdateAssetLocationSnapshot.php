<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Events\AssetLocationUpdated;
use App\Domains\Assets\Events\AssetLocationUpdatedBroadcast;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use DateTimeInterface;

class UpdateAssetLocationSnapshot
{
    public function execute(
        Asset $asset,
        float $latitude,
        float $longitude,
        LocationSource $source,
        ?DateTimeInterface $recordedAt = null,
        ?float $speed = null,
        ?int $heading = null,
        ?string $formattedLocation = null,
    ): AssetLocationSnapshot {
        $recordedAt = $recordedAt ?? now();

        $snapshot = AssetLocationSnapshot::create([
            'asset_id' => $asset->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'formatted_location' => $formattedLocation,
            'speed' => $speed,
            'heading' => $heading,
            'recorded_at' => $recordedAt,
            'source' => $source,
        ]);

        $asset->update(['last_seen_at' => $recordedAt]);

        $recordedAtString = $recordedAt instanceof DateTimeInterface
            ? $recordedAt->format('Y-m-d\TH:i:s\Z')
            : (string) $recordedAt;

        AssetLocationUpdated::dispatch(
            $asset->team_id,
            $asset->id,
            $latitude,
            $longitude,
            $recordedAtString,
        );

        broadcast(new AssetLocationUpdatedBroadcast(
            $asset->team_id,
            $asset->id,
            $latitude,
            $longitude,
            $recordedAtString,
        ));

        return $snapshot;
    }
}
