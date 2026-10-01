<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Data\IdleSummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class IdleTimeCalculator
{
    public const STOPPED_SPEED_KPH = 3.0;

    public const MIN_STOP_MINUTES = 3;

    public const SILENT_AFTER_MINUTES = 30;

    /** GPS points fetched per round trip by the fallback. */
    private const POINTS_CHUNK = 2000;

    /**
     * @param  list<int>  $assetIds
     * @return array<int, IdleSummary> keyed by asset id (only this team's assets)
     */
    public function forAssets(int $teamId, array $assetIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Snapshots carry no team_id: isolation comes from filtering assets by
        // team first and only querying snapshots for those ids.
        $assets = Asset::query()->where('team_id', $teamId)->whereIn('id', $assetIds)->get(['id', 'last_seen_at'])->keyBy('id');

        if ($assets->isEmpty()) {
            return [];
        }

        $ids = $assets->keys()->all();

        // State in force at `from`: the reading with the greatest recorded_at
        // before it, per asset (ids can be out of order after history backfills).
        $latestBefore = AssetTelemetrySnapshot::query()
            ->selectRaw('asset_id, max(recorded_at) as latest_at')
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition)
            ->where('recorded_at', '<', $from)
            ->groupBy('asset_id');

        $before = AssetTelemetrySnapshot::query()
            ->joinSub($latestBefore, 'lb', fn ($join) => $join
                ->on('lb.asset_id', '=', 'asset_telemetry_snapshots.asset_id')
                ->on('lb.latest_at', '=', 'asset_telemetry_snapshots.recorded_at'))
            ->where('asset_telemetry_snapshots.telemetry_type', TelemetryType::Ignition)
            ->get(['asset_telemetry_snapshots.id', 'asset_telemetry_snapshots.asset_id', 'asset_telemetry_snapshots.data_json', 'asset_telemetry_snapshots.recorded_at'])
            ->sortByDesc('id')
            ->unique('asset_id')
            ->values();

        $inside = AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['asset_id', 'data_json', 'recorded_at']);

        $readings = $before->concat($inside)->groupBy('asset_id');
        $engineStateIds = $this->assetsReportingIdle($ids);
        $summaries = [];
        $fallbackWindows = [];

        foreach ($assets as $id => $asset) {
            $series = $readings->get($id, collect())->sortBy('recorded_at')->values();
            $end = $this->effectiveEnd($asset->last_seen_at, $to);

            if (isset($engineStateIds[$id])) {
                $summaries[$id] = $this->fromEngineStates($series, $from, $end);

                continue;
            }

            $windows = $this->onWindows($series, $from, $end);

            if ($windows === []) {
                $summaries[$id] = new IdleSummary(0.0, 'none', []);

                continue;
            }

            $fallbackWindows[(int) $id] = $windows;
        }

        $fallback = $this->fromIgnitionAndSpeed($fallbackWindows, $from, $to);

        return $assets->keys()->mapWithKeys(fn ($id) => [$id => $summaries[$id] ?? $fallback[$id]])->all();
    }

    public function forAsset(Asset $asset, CarbonImmutable $from, CarbonImmutable $to): IdleSummary
    {
        return $this->forAssets((int) $asset->team_id, [(int) $asset->id], $from, $to)[(int) $asset->id]
            ?? new IdleSummary(0.0, 'none', []);
    }

    private function state(AssetTelemetrySnapshot $reading): string
    {
        return strtolower((string) ($reading->data_json['value'] ?? ''));
    }

    /** An asset silent for > SILENT_AFTER_MINUTES has no data after last_seen_at. */
    private function effectiveEnd(?CarbonInterface $lastSeen, CarbonImmutable $to): CarbonImmutable
    {
        if ($lastSeen === null) {
            return $to;
        }

        $lastSeen = CarbonImmutable::parse($lastSeen);

        return $lastSeen->lt($to->subMinutes(self::SILENT_AFTER_MINUTES)) ? $lastSeen : $to;
    }

    /**
     * Readings are stored only on change: each one lasts until the next.
     *
     * @param  Collection<int, AssetTelemetrySnapshot>  $series
     */
    private function fromEngineStates(Collection $series, CarbonImmutable $from, CarbonImmutable $end): IdleSummary
    {
        $segments = [];

        foreach ($series as $i => $reading) {
            if ($this->state($reading) !== 'idle') {
                continue;
            }

            $start = CarbonImmutable::parse($reading->recorded_at)->max($from);
            $next = $series->get($i + 1);
            $stop = ($next ? CarbonImmutable::parse($next->recorded_at) : $end)->min($end);

            if ($stop->gt($start)) {
                $segments[] = $this->segment($start, $stop);
            }
        }

        return $this->summary($segments, 'engine_state');
    }

    /**
     * Assets whose provider reports an Idle engine state at all (any time in
     * the retained history): their On windows are driving, never idling, so
     * they never use the GPS fallback. One grouped query for every id.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function assetsReportingIdle(array $ids): array
    {
        $value = AssetTelemetrySnapshot::query()->getQuery()->getGrammar()->wrap('data_json->value');

        $assetIds = AssetTelemetrySnapshot::query()
            ->toBase()
            ->select('asset_id')
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition->value)
            ->whereRaw("lower({$value}) = ?", ['idle'])
            ->groupBy('asset_id')
            ->pluck('asset_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($assetIds, true);
    }

    /**
     * Ignition On (or running) windows of one asset, clipped to [from, end],
     * in chronological order.
     *
     * @param  Collection<int, AssetTelemetrySnapshot>  $series
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function onWindows(Collection $series, CarbonImmutable $from, CarbonImmutable $end): array
    {
        $windows = [];

        foreach ($series as $i => $reading) {
            if (! in_array($this->state($reading), ['on', 'running'], true)) {
                continue;
            }

            $next = $series->get($i + 1);
            $windows[] = [
                CarbonImmutable::parse($reading->recorded_at)->max($from),
                ($next ? CarbonImmutable::parse($next->recorded_at) : $end)->min($end),
            ];
        }

        return $windows;
    }

    /**
     * Providers without an Idle state: ignition On while GPS says stopped
     * (< STOPPED_SPEED_KPH) for at least MIN_STOP_MINUTES. The GPS points of
     * every fallback asset are streamed by one ordered query (bounded memory)
     * and walked once, advancing through each asset's On windows.
     *
     * @param  array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>>  $windowsByAsset
     * @return array<int, IdleSummary>
     */
    private function fromIgnitionAndSpeed(array $windowsByAsset, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($windowsByAsset === []) {
            return [];
        }

        $segments = array_fill_keys(array_keys($windowsByAsset), []);
        $current = null;
        $window = 0;
        $runStart = null;
        $runEnd = null;

        $points = AssetLocationSnapshot::query()
            ->whereIn('asset_id', array_keys($windowsByAsset))
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('asset_id')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->select(['id', 'asset_id', 'speed', 'recorded_at'])
            ->lazy(self::POINTS_CHUNK);

        foreach ($points as $point) {
            $assetId = (int) $point->asset_id;

            if ($assetId !== $current) {
                if ($current !== null) {
                    $this->closeRun($segments[$current], $runStart, $runEnd);
                }

                $current = $assetId;
                $window = 0;
                $runStart = $runEnd = null;
            }

            $windows = $windowsByAsset[$assetId];
            $at = CarbonImmutable::parse($point->recorded_at);

            // Past the current On window: its run ends there.
            while ($window < count($windows) && $at->gt($windows[$window][1])) {
                $this->closeRun($segments[$assetId], $runStart, $runEnd);
                $runStart = $runEnd = null;
                $window++;
            }

            if ($window >= count($windows) || $at->lt($windows[$window][0])) {
                continue;
            }

            if ((float) $point->speed < self::STOPPED_SPEED_KPH) {
                $runStart ??= $at;
                $runEnd = $at;

                continue;
            }

            $this->closeRun($segments[$assetId], $runStart, $runEnd);
            $runStart = $runEnd = null;
        }

        if ($current !== null) {
            $this->closeRun($segments[$current], $runStart, $runEnd);
        }

        return array_map(fn (array $assetSegments) => $this->summary($assetSegments, 'ignition_speed'), $segments);
    }

    /** @param list<array{from: string, to: string, minutes: int}> $segments */
    private function closeRun(array &$segments, ?CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        if ($start !== null && $end !== null && abs($start->diffInMinutes($end)) >= self::MIN_STOP_MINUTES) {
            $segments[] = $this->segment($start, $end);
        }
    }

    /** @return array{from: string, to: string, minutes: int} */
    private function segment(CarbonImmutable $start, CarbonImmutable $stop): array
    {
        return ['from' => $start->toIso8601String(), 'to' => $stop->toIso8601String(), 'minutes' => (int) round(abs($start->diffInMinutes($stop)))];
    }

    /** @param list<array{from: string, to: string, minutes: int}> $segments */
    private function summary(array $segments, string $source): IdleSummary
    {
        $minutes = array_sum(array_column($segments, 'minutes'));

        return new IdleSummary(round($minutes / 60, 2), $segments === [] ? 'none' : $source, $segments);
    }
}
