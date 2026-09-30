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

        // State in force at `from`: newest reading before it, per asset.
        $before = AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition)
            ->whereIn('id', AssetTelemetrySnapshot::query()
                ->selectRaw('max(id)')
                ->whereIn('asset_id', $ids)
                ->where('telemetry_type', TelemetryType::Ignition)
                ->where('recorded_at', '<', $from)
                ->groupBy('asset_id'))
            ->get(['asset_id', 'data_json', 'recorded_at']);

        $inside = AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Ignition)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['asset_id', 'data_json', 'recorded_at']);

        $readings = $before->concat($inside)->groupBy('asset_id');
        $summaries = [];

        foreach ($assets as $id => $asset) {
            $series = $readings->get($id, collect())->sortBy('recorded_at')->values();
            $end = $this->effectiveEnd($asset->last_seen_at, $to);
            $summaries[$id] = $series->contains(fn ($r) => $this->state($r) === 'idle')
                ? $this->fromEngineStates($series, $from, $end)
                : $this->fromIgnitionAndSpeed((int) $id, $series, $from, $end);
        }

        return $summaries;
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
     * Providers without an Idle state: ignition On while GPS says stopped
     * (< STOPPED_SPEED_KPH) for at least MIN_STOP_MINUTES.
     *
     * @param  Collection<int, AssetTelemetrySnapshot>  $series
     */
    private function fromIgnitionAndSpeed(int $assetId, Collection $series, CarbonImmutable $from, CarbonImmutable $end): IdleSummary
    {
        $onWindows = [];

        foreach ($series as $i => $reading) {
            if ($this->state($reading) !== 'on') {
                continue;
            }

            $next = $series->get($i + 1);
            $onWindows[] = [
                CarbonImmutable::parse($reading->recorded_at)->max($from),
                ($next ? CarbonImmutable::parse($next->recorded_at) : $end)->min($end),
            ];
        }

        if ($onWindows === []) {
            return new IdleSummary(0.0, 'none', []);
        }

        $points = AssetLocationSnapshot::query()
            ->where('asset_id', $assetId)
            ->whereBetween('recorded_at', [$from, $end])
            ->orderBy('recorded_at')
            ->get(['speed', 'recorded_at']);

        $segments = [];

        foreach ($onWindows as [$windowStart, $windowEnd]) {
            $runStart = null;
            $runEnd = null;

            foreach ($points as $point) {
                $at = CarbonImmutable::parse($point->recorded_at);

                if ($at->lt($windowStart) || $at->gt($windowEnd)) {
                    continue;
                }

                if ((float) $point->speed < self::STOPPED_SPEED_KPH) {
                    $runStart ??= $at;
                    $runEnd = $at;

                    continue;
                }

                $this->closeRun($segments, $runStart, $runEnd);
                $runStart = $runEnd = null;
            }

            $this->closeRun($segments, $runStart, $runEnd);
        }

        return $this->summary($segments, 'ignition_speed');
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
