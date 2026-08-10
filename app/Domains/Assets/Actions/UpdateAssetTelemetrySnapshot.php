<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The single writer for `asset_telemetry_snapshots` — the telemetry counterpart
 * of {@see UpdateAssetLocationSnapshot}.
 *
 * A reading is identified by (asset, type, recorded_at): the provider stats
 * snapshot repeats the same timestamped reading until the device sends a new
 * one, so re-reading it on every poll must update in place rather than grow the
 * table by one row per tick.
 */
class UpdateAssetTelemetrySnapshot
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        Asset $asset,
        TelemetryType $type,
        array $data,
        ?DateTimeInterface $recordedAt = null,
        ?string $sourceEventId = null,
    ): AssetTelemetrySnapshot {
        $recordedAt = $recordedAt !== null ? CarbonImmutable::instance($recordedAt) : CarbonImmutable::now();

        $snapshot = AssetTelemetrySnapshot::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'telemetry_type' => $type,
                'recorded_at' => $recordedAt,
            ],
            [
                'data_json' => $data,
                'source_event_id' => $sourceEventId,
            ],
        );

        $this->bumpLastSeenAt($asset, $recordedAt);

        return $snapshot;
    }

    /**
     * Telemetry is a real signal from the device, so it moves `last_seen_at`
     * exactly like a location does — but only forward: a backfilled or stale
     * reading must never make a live asset look older than it is.
     */
    private function bumpLastSeenAt(Asset $asset, CarbonImmutable $recordedAt): void
    {
        if ($asset->last_seen_at !== null && $asset->last_seen_at->greaterThanOrEqualTo($recordedAt)) {
            return;
        }

        $asset->update(['last_seen_at' => $recordedAt]);
    }
}
