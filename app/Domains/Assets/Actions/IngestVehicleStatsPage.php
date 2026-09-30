<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Data\VehicleStatsIngestResult;
use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Queries\LatestAssetTelemetry;
use App\Domains\Context\Support\HaversineDistance;
use App\Domains\Integrations\Data\VehicleStatsPage;
use App\Domains\Integrations\Models\TenantIntegration;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persist one page of a provider's vehicle stats (feed or history backfill).
 *
 * Built for a page every few seconds, so the cost is per page, not per point:
 *
 * - the page's vehicles are resolved to assets in two queries, filtered by the
 *   integration's team (provider ids are unique platform-wide, never per
 *   tenant, so isolation cannot rest on them);
 * - GPS points and readings are written with one bulk insert-or-ignore per
 *   chunk against unique indexes, so a replayed page (retry, backfill overlap)
 *   stores nothing twice;
 * - readings are kept only when they change, judged against the newest stored
 *   reading of every (asset, type), loaded in one query;
 * - the asset row carries the current position and motion state, updated only
 *   by points newer than what it already holds.
 *
 * It does not broadcast or raise detections: the caller does that once the
 * surrounding transaction (which also advances the feed cursor) commits.
 */
class IngestVehicleStatsPage
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly ResolveAssetsFromExternalIds $resolveAssets,
    ) {}

    public function execute(TenantIntegration $integration, VehicleStatsPage $page): VehicleStatsIngestResult
    {
        $externalIds = [];
        $withoutId = 0;

        foreach ([...$page->locations, ...$page->readings] as $point) {
            $externalId = (string) ($point['external_id'] ?? '');

            if ($externalId !== '') {
                $externalIds[$externalId] = true;
            } else {
                $withoutId++;
            }
        }

        if ($externalIds === []) {
            return new VehicleStatsIngestResult(dropped: $this->counted(['no_external_id' => $withoutId]));
        }

        $assets = $this->resolveAssets->execute(
            $integration->provider_id,
            array_map('strval', array_keys($externalIds)),
            $integration->team_id,
        );

        if ($assets === []) {
            $points = count($page->locations) + count($page->readings);

            return new VehicleStatsIngestResult(dropped: $this->counted([
                'no_external_id' => $withoutId,
                'unknown_vehicle' => $points - $withoutId,
            ]));
        }

        [$locationsStored, $positions, $newestLocation, $locationsDropped] = $this->storeLocations($assets, $page->locations);
        [$readingsStored, $telemetry, $newestReading, $readingsDropped] = $this->storeReadings($assets, $page->readings);

        $dropped = $locationsDropped;

        foreach ($readingsDropped as $reason => $count) {
            $dropped[$reason] = ($dropped[$reason] ?? 0) + $count;
        }

        $newest = $newestLocation;

        if ($newestReading !== null && ($newest === null || $newestReading->greaterThan($newest))) {
            $newest = $newestReading;
        }

        return new VehicleStatsIngestResult(
            locationsStored: $locationsStored,
            readingsStored: $readingsStored,
            newestPointAt: $newest,
            positions: $positions,
            telemetry: $telemetry,
            dropped: $dropped,
        );
    }

    /**
     * Only the reasons that actually dropped something.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function counted(array $counts): array
    {
        return array_filter($counts, fn (int $count) => $count > 0);
    }

    /**
     * Why a point could not be tied to an asset of this tenant, or null when
     * it was.
     *
     * @param  array<string, mixed>  $point
     */
    private function unresolvedReason(array $point): string
    {
        return (string) ($point['external_id'] ?? '') === '' ? 'no_external_id' : 'unknown_vehicle';
    }

    /**
     * @param  array<string, Asset>  $assets
     * @param  list<array<string, mixed>>  $locations
     * @return array{0: int, 1: array<int, array<string, mixed>>, 2: CarbonInterface|null, 3: array<string, int>}
     */
    private function storeLocations(array $assets, array $locations): array
    {
        $now = now();
        $rows = [];
        $dropped = [];
        /** @var array<int, list<array<string, mixed>>> $pointsByAsset */
        $pointsByAsset = [];
        $newest = null;

        foreach ($locations as $location) {
            $asset = $assets[(string) ($location['external_id'] ?? '')] ?? null;

            if ($asset === null) {
                $reason = $this->unresolvedReason($location);
                $dropped[$reason] = ($dropped[$reason] ?? 0) + 1;

                continue;
            }

            if (! isset($location['latitude'], $location['longitude'])) {
                $dropped['missing_coordinates'] = ($dropped['missing_coordinates'] ?? 0) + 1;

                continue;
            }

            $at = $this->instant($location['recorded_at'] ?? null);
            $point = [
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'speed' => isset($location['speed']) ? (float) $location['speed'] : null,
                'heading' => isset($location['heading']) ? (int) $location['heading'] : null,
                'formatted_location' => $location['formatted_location'] ?? null,
                'at' => $at,
            ];

            $pointsByAsset[$asset->id][] = $point;

            $rows[] = [
                'asset_id' => $asset->id,
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'formatted_location' => $point['formatted_location'],
                'speed' => $point['speed'],
                'heading' => $point['heading'],
                'recorded_at' => $this->column($at),
                'source' => LocationSource::Provider->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($newest === null || $at->greaterThan($newest)) {
                $newest = $at;
            }
        }

        $stored = 0;

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $stored += DB::table('asset_location_snapshots')->insertOrIgnore($chunk);
        }

        // Rows the unique index ignored: already stored by an earlier page.
        $dropped['already_stored'] = count($rows) - $stored;

        $assetsById = [];

        foreach ($assets as $asset) {
            $assetsById[$asset->id] = $asset;
        }

        $positions = [];

        foreach ($pointsByAsset as $assetId => $points) {
            $position = $this->advanceLivePosition($assetsById[$assetId], $points);

            if ($position !== null) {
                $positions[$assetId] = $position;
            }
        }

        return [$stored, $positions, $newest, $this->counted($dropped)];
    }

    /**
     * Move the asset's current position and motion state forward through the
     * points newer than what it holds. Older points (a backfill, a late page)
     * are history only: they never rewind the live state.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array<string, mixed>|null the new current position, or null when nothing moved forward
     */
    private function advanceLivePosition(Asset $asset, array $points): ?array
    {
        usort($points, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        $threshold = (float) config('telematics.moving_speed_kph', 5.0);
        $exitRadius = (float) config('telematics.stop_exit_radius_m', 50);
        $latest = null;

        foreach ($points as $point) {
            if ($asset->last_location_at !== null && $point['at']->lessThanOrEqualTo($asset->last_location_at)) {
                continue;
            }

            $latest = $point;

            // A point without speed says nothing about motion; it still moves
            // the marker.
            if ($point['speed'] === null) {
                continue;
            }

            $fast = $point['speed'] >= $threshold;

            if ($asset->stopped_since !== null) {
                // Stopped: only leaving the place ends the stop. A parked
                // unit's GPS reads phantom speeds, so speed alone is not a
                // departure.
                if ($asset->stop_latitude === null || $asset->stop_longitude === null) {
                    $asset->stop_latitude = $point['latitude'];
                    $asset->stop_longitude = $point['longitude'];
                }

                $leftPlace = HaversineDistance::meters(
                    $asset->stop_latitude,
                    $asset->stop_longitude,
                    $point['latitude'],
                    $point['longitude'],
                ) > $exitRadius;

                if ($fast && $leftPlace) {
                    $asset->last_moving_at = $point['at'];
                    $asset->stopped_since = null;
                    $asset->stop_latitude = null;
                    $asset->stop_longitude = null;
                }
            } elseif ($fast) {
                $asset->last_moving_at = $point['at'];
            } else {
                $asset->stopped_since = $point['at'];
                $asset->stop_latitude = $point['latitude'];
                $asset->stop_longitude = $point['longitude'];
            }
        }

        if ($latest === null) {
            return null;
        }

        $asset->forceFill([
            'last_latitude' => $latest['latitude'],
            'last_longitude' => $latest['longitude'],
            'last_speed_kph' => $latest['speed'],
            'last_heading' => $latest['heading'],
            'last_formatted_location' => $latest['formatted_location'] ?? $asset->last_formatted_location,
            'last_location_at' => $latest['at'],
            'last_seen_at' => $asset->last_seen_at === null || $latest['at']->greaterThan($asset->last_seen_at)
                ? $latest['at']
                : $asset->last_seen_at,
        ])->save();

        return [
            'asset_id' => $asset->id,
            'latitude' => $latest['latitude'],
            'longitude' => $latest['longitude'],
            'speed_kph' => $latest['speed'],
            'heading' => $latest['heading'],
            'recorded_at' => $latest['at']->toIso8601ZuluString(),
            'moving' => $asset->stopped_since === null && $asset->last_moving_at !== null,
        ];
    }

    /**
     * @param  array<string, Asset>  $assets
     * @param  list<array<string, mixed>>  $readings
     * @return array{0: int, 1: array<int, array<string, array<string, mixed>>>, 2: CarbonInterface|null, 3: array<string, int>}
     */
    private function storeReadings(array $assets, array $readings): array
    {
        /** @var array<string, list<array<string, mixed>>> $runs keyed "assetId|type" */
        $runs = [];
        $dropped = [];

        foreach ($readings as $reading) {
            $asset = $assets[(string) ($reading['external_id'] ?? '')] ?? null;
            $type = $reading['type'] ?? null;

            $reason = match (true) {
                $asset === null => $this->unresolvedReason($reading),
                ! $type instanceof TelemetryType => 'unsupported_type',
                ! isset($reading['value']) => 'missing_value',
                default => null,
            };

            if ($reason !== null) {
                $dropped[$reason] = ($dropped[$reason] ?? 0) + 1;

                continue;
            }

            $runs[$asset->id.'|'.$type->value][] = [
                'asset_id' => $asset->id,
                'type' => $type,
                'value' => $reading['value'],
                'unit' => $reading['unit'] ?? null,
                'at' => $this->instant($reading['recorded_at'] ?? null),
            ];
        }

        if ($runs === []) {
            return [0, [], null, $dropped];
        }

        $latest = $this->latestReadings(array_values(array_unique(array_map(
            fn (array $run) => $run[0]['asset_id'],
            $runs,
        ))));

        $now = now();
        $rows = [];
        $telemetry = [];
        $newest = null;

        foreach ($runs as $key => $run) {
            usort($run, fn (array $a, array $b) => $a['at'] <=> $b['at']);

            $previousValue = ($latest[$key] ?? null)?->data_json['value'] ?? null;
            $previousAt = ($latest[$key] ?? null)?->recorded_at;

            foreach ($run as $reading) {
                if (! RecordAssetTelemetry::isNewReading($previousValue, $previousAt, $reading['value'], $reading['at'])) {
                    $dropped['unchanged_value'] = ($dropped['unchanged_value'] ?? 0) + 1;

                    continue;
                }

                $rows[] = [
                    'asset_id' => $reading['asset_id'],
                    'telemetry_type' => $reading['type']->value,
                    'data_json' => json_encode(['value' => $reading['value'], 'unit' => $reading['unit']]),
                    'recorded_at' => $this->column($reading['at']),
                    'source_event_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $telemetry[$reading['asset_id']][$reading['type']->value] = [
                    'value' => $reading['value'],
                    'unit' => $reading['unit'],
                    'recorded_at' => $reading['at']->toIso8601ZuluString(),
                ];

                $previousValue = $reading['value'];
                $previousAt = $reading['at'];

                if ($newest === null || $reading['at']->greaterThan($newest)) {
                    $newest = $reading['at'];
                }
            }
        }

        $stored = 0;

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $stored += DB::table('asset_telemetry_snapshots')->insertOrIgnore($chunk);
        }

        $dropped['already_stored'] = count($rows) - $stored;

        return [$stored, $telemetry, $newest, $this->counted($dropped)];
    }

    /**
     * The newest stored reading of every (asset, type) pair of these assets,
     * keyed "assetId|type". Runs on every feed tick: see LatestAssetTelemetry
     * for why it is not a grouped MAX over the whole retention window.
     *
     * @param  list<int>  $assetIds
     * @return array<string, AssetTelemetrySnapshot|null>
     */
    private function latestReadings(array $assetIds): array
    {
        return app(LatestAssetTelemetry::class)->byType($assetIds);
    }

    /**
     * Truncated to whole seconds like the columns it lands in, so comparing a
     * fresh point against a stored one never mistakes sub-second noise for a
     * newer point.
     */
    private function instant(mixed $recordedAt): CarbonInterface
    {
        $at = $recordedAt !== null && $recordedAt !== ''
            ? Carbon::parse($recordedAt)
            : now();

        return $at->utc()->startOfSecond();
    }

    /**
     * Storage format of a timestamp column: UTC, whole seconds — the same
     * truncation Eloquent applies, so the unique indexes see one value per
     * instant whichever path wrote it.
     */
    private function column(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Y-m-d H:i:s');
    }
}
