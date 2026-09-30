<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Data\CopilotPeriod;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Data\IdleSummary;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Copilot\Support\FuelConsumption;
use App\Domains\Copilot\Support\IdleTimeCalculator;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Compares every unit of the tenant on one metric over the period and flags
 * the outliers (above average + 1.5 standard deviations). Every metric is
 * computed with a fixed number of grouped queries, whatever the fleet size.
 */
final class RankAssetsTool implements CopilotTool
{
    public const METRICS = [
        'fuel_used_pct' => ['label' => 'Combustible consumido', 'unit' => '% tanque', 'permission' => 'assets.view'],
        'distance_km' => ['label' => 'Distancia recorrida', 'unit' => 'km', 'permission' => 'assets.view'],
        'idle_hours' => ['label' => 'Ralentí', 'unit' => 'h', 'permission' => 'assets.view'],
        'incidents' => ['label' => 'Incidentes', 'unit' => 'incidentes', 'permission' => 'incidents.view'],
        'events' => ['label' => 'Eventos', 'unit' => 'eventos', 'permission' => 'incidents.view'],
        'panics' => ['label' => 'Pánicos', 'unit' => 'pánicos', 'permission' => 'incidents.view'],
    ];

    /** Metrics where a unit without rows had zero of them (not "no data"). */
    private const COUNT_METRICS = ['incidents', 'events', 'panics'];

    private const MAX_ASSETS = 2000;

    private const PANIC_CODE = 'panic_button';

    public function __construct(private readonly IdleTimeCalculator $idle) {}

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        $metric = (string) ($context->arguments['metric'] ?? '');
        $meta = self::METRICS[$metric] ?? throw new InvalidArgumentException('Unknown ranking metric.');

        if (! $context->can($meta['permission'])) {
            return CopilotToolResult::denied('rank_assets', 'Ranking de unidades', $meta['permission'] === 'incidents.view' ? 'incidentes' : 'activos');
        }

        $assets = Asset::query()
            ->where('team_id', $context->teamId)
            ->when($context->category, fn ($q, $category) => $q->whereHas('assetType', fn ($t) => $t->where('category', $category->value)))
            ->orderBy('id')
            ->limit(self::MAX_ASSETS)
            ->get(['id', 'code', 'name', 'team_id', 'last_seen_at'])
            ->keyBy('id');

        $ids = $assets->keys()->map(fn ($id) => (int) $id);
        $period = $context->period;

        $values = $ids->isEmpty() ? collect() : match ($metric) {
            'idle_hours' => collect($this->idle->forAssets($context->teamId, $ids->all(), $period->from, $period->to))
                ->map(fn (IdleSummary $summary) => $summary->hours),
            'fuel_used_pct' => $this->fuel($ids, $period),
            'distance_km' => $this->distance($ids, $period),
            'incidents' => $this->countIncidents($context, $ids),
            'events' => $this->countEvents($context, $ids, $context->arguments['event_type'] ?? null),
            'panics' => $this->countEvents($context, $ids, self::PANIC_CODE),
        };

        $values = $values->only($ids->all())->map(fn ($v) => (float) $v);
        $ascending = ($context->arguments['order'] ?? 'desc') === 'asc';

        if (in_array($metric, self::COUNT_METRICS, true)) {
            // A unit without rows had zero of them: it belongs in the ranking.
            $values = $ids->mapWithKeys(fn (int $id) => [$id => (float) $values->get($id, 0.0)]);
            $withoutData = 0;
            $emptyText = "Ninguna unidad registró {$meta['label']} en {$period->label}.";
            $isEmpty = $values->sum() <= 0;
        } else {
            // Telemetry: 0 means no readings, not "the least"; those units are left out.
            $values = $values->filter(fn (float $v) => $v > 0);
            $withoutData = $ids->count() - $values->count();
            $emptyText = "No hay datos de {$meta['label']} en {$period->label}.";
            $isEmpty = $values->isEmpty();
        }

        if ($isEmpty) {
            return new CopilotToolResult(
                tool: 'rank_assets',
                label: 'Ranking de unidades',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => $emptyText]],
                facts: ['metric' => $metric, 'unit' => $meta['unit'], 'period' => $period->label, 'items' => [], 'average' => null],
                highlights: [$emptyText],
            );
        }

        $avg = (float) $values->avg();
        $std = sqrt((float) $values->map(fn (float $v) => ($v - $avg) ** 2)->avg());
        $limit = (int) ($context->arguments['limit'] ?? 5);
        // Descending lists never show units at zero; they still weigh on the average.
        $sorted = $ascending ? $values->sort() : $values->sortDesc()->filter(fn (float $v) => $v > 0);

        $items = $sorted->take($limit)->map(function (float $value, int $id) use ($assets, $avg, $std, $context): array {
            $asset = $assets->get($id);

            return [
                'assetId' => $id,
                'code' => $asset->code,
                'name' => (string) $asset->name,
                'value' => round($value, 2),
                'outlier' => $std > 0 && $value > $avg + 1.5 * $std,
                'href' => CopilotPresenter::assetHref($context->teamSlug, $id),
            ];
        })->values()->all();

        $average = round($avg, 2);
        $top = $items[0];
        $topName = $top['code'] ?? $top['name'];
        $outliers = array_values(array_filter($items, fn (array $i) => $i['outlier']));

        $highlights = ["{$topName} ".($ascending ? 'tiene el menor valor de' : 'encabeza')
            ." {$meta['label']} con {$top['value']} {$meta['unit']} (promedio de flota {$average} {$meta['unit']}, {$period->label})."];

        if ($outliers !== []) {
            $highlights[] = 'Atípicas: '.implode(', ', array_map(fn (array $i) => $i['code'] ?? $i['name'], $outliers)).'.';
        }

        if ($withoutData > 0) {
            $highlights[] = "{$withoutData} unidad(es) sin datos de {$meta['label']} en el periodo quedaron fuera del ranking.";
        }

        return new CopilotToolResult(
            tool: 'rank_assets',
            label: 'Ranking de unidades',
            blocks: [[
                'type' => 'ranking',
                'metric' => $metric,
                'label' => $meta['label'],
                'unit' => $meta['unit'],
                'items' => $items,
                'average' => $average,
            ]],
            sources: array_map(fn (array $i) => [
                'kind' => 'asset',
                'id' => $i['assetId'],
                'label' => (string) ($i['code'] ?? $i['name']),
                'href' => $i['href'],
            ], $items),
            facts: [
                'metric' => $metric,
                'unit' => $meta['unit'],
                'period' => $period->label,
                'items' => $items,
                'average' => $average,
                'units_with_data' => $values->count(),
                'units_without_data' => $withoutData,
            ],
            highlights: $highlights,
        );
    }

    /**
     * Snapshots carry no team_id: isolation comes from the tenant's asset ids.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, float> asset_id → % of tank consumed
     */
    private function fuel(Collection $ids, CopilotPeriod $period): Collection
    {
        return AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Fuel)
            ->whereBetween('recorded_at', [$period->from, $period->to])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['asset_id', 'data_json', 'recorded_at'])
            ->groupBy('asset_id')
            ->map(fn (Collection $series) => FuelConsumption::fromSeries($series)['consumed']);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, float> asset_id → km (odometer delta)
     */
    private function distance(Collection $ids, CopilotPeriod $period): Collection
    {
        return AssetTelemetrySnapshot::query()
            ->whereIn('asset_id', $ids)
            ->where('telemetry_type', TelemetryType::Odometer)
            ->whereBetween('recorded_at', [$period->from, $period->to])
            ->get(['asset_id', 'data_json'])
            ->groupBy('asset_id')
            ->map(function (Collection $series): float {
                $readings = $series->map(fn (AssetTelemetrySnapshot $r) => (float) ($r->data_json['value'] ?? 0));

                return max(0.0, (float) $readings->max() - (float) $readings->min());
            });
    }

    /**
     * Incidents opened in the period, per unit.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function countIncidents(CopilotToolContext $context, Collection $ids): Collection
    {
        return Incident::query()
            ->where('team_id', $context->teamId)
            ->whereIn('asset_id', $ids)
            ->whereBetween('opened_at', [$context->period->from, $context->period->to])
            ->selectRaw('asset_id, count(*) as total')
            ->groupBy('asset_id')
            ->pluck('total', 'asset_id')
            ->map(fn ($v) => (int) $v);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function countEvents(CopilotToolContext $context, Collection $ids, ?string $typeCode): Collection
    {
        return NormalizedEvent::query()
            ->where('team_id', $context->teamId)
            ->whereIn('asset_id', $ids)
            ->whereBetween('occurred_at', [$context->period->from, $context->period->to])
            ->when($typeCode, fn ($q, $code) => $q->whereIn('event_type_id', EventType::query()->where('code', $code)->select('id')))
            ->selectRaw('asset_id, count(*) as total')
            ->groupBy('asset_id')
            ->pluck('total', 'asset_id')
            ->map(fn ($v) => (int) $v);
    }
}
