<?php

namespace App\Domains\Assets\Queries;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Newest telemetry reading per (asset, type).
 *
 * The one-of-many shape (`MAX(recorded_at) … GROUP BY asset_id, type`) reads
 * every retained reading of the requested assets (90 days) and it runs on
 * every telematics tick. On PostgreSQL each (asset, type) pair is instead a
 * LATERAL `ORDER BY recorded_at DESC LIMIT 1` — one backward seek on the
 * unique (asset_id, telemetry_type, recorded_at) index. Other drivers keep
 * the portable grouped query.
 *
 * Callers pass asset ids they already resolved inside their tenant.
 */
final class LatestAssetTelemetry
{
    /**
     * @param  list<int>  $assetIds
     * @param  list<TelemetryType>|null  $types  null = every type
     * @return array<string, AssetTelemetrySnapshot> keyed "assetId|type"
     */
    public function byType(array $assetIds, ?array $types = null): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        if ($assetIds === []) {
            return [];
        }

        $typeValues = array_map(fn (TelemetryType $type) => $type->value, $types ?? TelemetryType::cases());

        $snapshots = (new AssetTelemetrySnapshot)->getConnection()->getDriverName() === 'pgsql'
            ? $this->lateral($assetIds, $typeValues)
            : $this->grouped($assetIds, $typeValues);

        $latest = [];

        foreach ($snapshots as $snapshot) {
            $key = $snapshot->asset_id.'|'.$snapshot->telemetry_type->value;
            $current = $latest[$key] ?? null;

            if ($current === null || $this->isNewer($snapshot, $current)) {
                $latest[$key] = $snapshot;
            }
        }

        return $latest;
    }

    /**
     * Set `latestTelemetry` (newest reading of any type) and
     * `latestSpeedTelemetry` on the given assets, the same rows the
     * one-of-many relations resolve, without their full-history scan.
     *
     * @param  iterable<Asset>  $assets
     */
    public function loadInto(iterable $assets): void
    {
        $assets = collect($assets)->filter();

        if ($assets->isEmpty()) {
            return;
        }

        $latest = $this->byType(array_values($assets->map(fn (Asset $asset) => (int) $asset->getKey())->all()));
        $byAsset = [];

        foreach ($latest as $snapshot) {
            $byAsset[$snapshot->asset_id][] = $snapshot;
        }

        foreach ($assets as $asset) {
            $newest = null;

            foreach ($byAsset[$asset->id] ?? [] as $snapshot) {
                if ($newest === null || $this->isNewer($snapshot, $newest)) {
                    $newest = $snapshot;
                }
            }

            $asset->setRelation('latestTelemetry', $newest);
            $asset->setRelation('latestSpeedTelemetry', $latest[$asset->id.'|'.TelemetryType::Speed->value] ?? null);
        }
    }

    /**
     * @param  list<int>  $assetIds
     * @param  list<string>  $typeValues
     * @return list<AssetTelemetrySnapshot>
     */
    private function lateral(array $assetIds, array $typeValues): array
    {
        $rows = DB::connection((new AssetTelemetrySnapshot)->getConnectionName())->select(<<<'SQL'
            SELECT s.*
            FROM unnest(?::bigint[]) AS a(id)
            CROSS JOIN unnest(?::text[]) AS t(type)
            CROSS JOIN LATERAL (
                SELECT *
                FROM asset_telemetry_snapshots
                WHERE asset_telemetry_snapshots.asset_id = a.id
                  AND asset_telemetry_snapshots.telemetry_type = t.type
                ORDER BY asset_telemetry_snapshots.recorded_at DESC, asset_telemetry_snapshots.id DESC
                LIMIT 1
            ) AS s
            SQL, ['{'.implode(',', $assetIds).'}', '{'.implode(',', $typeValues).'}']);

        return array_values(AssetTelemetrySnapshot::hydrate($rows)->all());
    }

    /**
     * @param  list<int>  $assetIds
     * @param  list<string>  $typeValues
     * @return list<AssetTelemetrySnapshot>
     */
    private function grouped(array $assetIds, array $typeValues): array
    {
        $newest = AssetTelemetrySnapshot::query()
            ->selectRaw('asset_id, telemetry_type, MAX(recorded_at) AS newest_at')
            ->whereIn('asset_id', $assetIds)
            ->whereIn('telemetry_type', $typeValues)
            ->groupBy('asset_id', 'telemetry_type');

        return array_values(AssetTelemetrySnapshot::query()
            ->joinSub($newest, 'newest', fn ($join) => $join
                ->on('asset_telemetry_snapshots.asset_id', '=', 'newest.asset_id')
                ->on('asset_telemetry_snapshots.telemetry_type', '=', 'newest.telemetry_type')
                ->on('asset_telemetry_snapshots.recorded_at', '=', 'newest.newest_at'))
            ->get(['asset_telemetry_snapshots.*'])
            ->all());
    }

    private function isNewer(AssetTelemetrySnapshot $candidate, AssetTelemetrySnapshot $current): bool
    {
        $cmp = $candidate->recorded_at <=> $current->recorded_at;

        return $cmp > 0 || ($cmp === 0 && $candidate->id > $current->id);
    }
}
