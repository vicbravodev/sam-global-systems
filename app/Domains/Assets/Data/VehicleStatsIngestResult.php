<?php

namespace App\Domains\Assets\Data;

use Carbon\CarbonInterface;

/**
 * What one ingested page of vehicle stats changed, for the caller to push out
 * (sockets) and react to (detectors) after the data is committed.
 */
final class VehicleStatsIngestResult
{
    /**
     * @param  array<int, array{asset_id: int, latitude: float, longitude: float, speed_kph: float|null, heading: int|null, recorded_at: string, moving: bool|null}>  $positions  current position per asset whose position moved forward
     * @param  array<int, array<string, array{value: float|string, unit: string|null, recorded_at: string}>>  $telemetry  newest changed reading per asset and type
     * @param  array<string, int>  $dropped  points not stored, counted by reason (no_external_id, no_monitored_asset, missing_coordinates, unsupported_type, missing_value, unchanged_value, already_stored)
     */
    public function __construct(
        public int $locationsStored = 0,
        public int $readingsStored = 0,
        public ?CarbonInterface $newestPointAt = null,
        public array $positions = [],
        public array $telemetry = [],
        public array $dropped = [],
    ) {}

    /**
     * Folds a later page into this one: counters add up, and for positions and
     * telemetry the later page wins, since it is newer. Drop counts add up
     * by reason.
     */
    public function merge(self $later): self
    {
        $newest = $this->newestPointAt;

        if ($later->newestPointAt !== null && ($newest === null || $later->newestPointAt->greaterThan($newest))) {
            $newest = $later->newestPointAt;
        }

        $telemetry = $this->telemetry;

        foreach ($later->telemetry as $assetId => $types) {
            $telemetry[$assetId] = array_merge($telemetry[$assetId] ?? [], $types);
        }

        $dropped = $this->dropped;

        foreach ($later->dropped as $reason => $count) {
            $dropped[$reason] = ($dropped[$reason] ?? 0) + $count;
        }

        return new self(
            locationsStored: $this->locationsStored + $later->locationsStored,
            readingsStored: $this->readingsStored + $later->readingsStored,
            newestPointAt: $newest,
            positions: array_replace($this->positions, $later->positions),
            telemetry: $telemetry,
            dropped: $dropped,
        );
    }
}
