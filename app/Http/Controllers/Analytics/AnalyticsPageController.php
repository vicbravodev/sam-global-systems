<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Assets\Models\Asset;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analytics page (Roadmap F13): KPI dashboard + report definitions with
 * generation and PDF/XLSX download. Mutations reuse the Analytics API
 * controllers as web routes.
 */
class AnalyticsPageController extends Controller
{
    /** Selectable analysis windows, in days. */
    private const PERIODS = [7, 30, 90];

    private const DEFAULT_PERIOD = 30;

    /**
     * Metrics that describe a level at a point in time (not activity), so the
     * period shows their latest value instead of a sum of daily values.
     */
    private const LEVEL_METRICS = ['active_assets'];

    /** Display order of metric groups (by code prefix). */
    private const GROUPS = ['incidents', 'ai', 'decisions', 'usage'];

    /**
     * Daily rates/averages and the daily count they are measured over. A plain
     * mean of daily values weighs a quiet day (one incident) like a busy one
     * and counts days without activity as 0 min / 0 %, so the period value is
     * the mean weighted by that count, and days where the count is 0 carry no
     * value at all (they are dropped from the series).
     */
    private const WEIGHTS = [
        'incidents_mttr_minutes' => 'incidents_resolved',
        'decisions_human_review_rate' => 'decisions_total',
        'ai_accuracy_rate' => 'decisions_total',
        'ai_human_override_rate' => 'decisions_total',
        'ai_false_positive_rate' => 'ai_total_evaluations',
        'ai_real_event_rate' => 'ai_total_evaluations',
        'ai_average_confidence' => 'ai_total_evaluations',
    ];

    public function show(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', KpiRecord::class);

        $period = in_array((int) $request->query('period'), self::PERIODS, true)
            ? (int) $request->query('period')
            : self::DEFAULT_PERIOD;

        $from = now()->subDays($period - 1)->startOfDay();
        $previousFrom = $from->copy()->subDays($period);

        return Inertia::render('analytics/index', [
            'period' => $period,
            'periods' => self::PERIODS,
            'range' => [
                'from' => $from->toDateString(),
                'to' => now()->toDateString(),
                'previousFrom' => $previousFrom->toDateString(),
                'previousTo' => $from->copy()->subDay()->toDateString(),
            ],
            // Live fleet level for the key-figures strip: what is watched (and
            // billed) right now, not the nightly KPI that counts every asset.
            'fleet' => fn (): array => [
                'monitored' => Asset::query()->where('team_id', $current_team->id)->monitored()->count(),
                'total' => Asset::query()->where('team_id', $current_team->id)->count(),
            ],
            // Daily tenant-wide KPIs of the selected window, one row per metric
            // with its period value, the value over the previous window of the
            // same length (for the change) and its daily series (trend chart).
            'metrics' => fn (): array => $this->metrics($current_team->id, $from, $previousFrom),
            'reports' => fn () => ReportDefinition::query()
                ->where(fn (Builder $q) => $q
                    ->whereNull('team_id')
                    ->orWhere('team_id', $current_team->id))
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (ReportDefinition $report): array => [
                    'id' => (int) $report->id,
                    'code' => $report->code,
                    'name' => $report->name,
                    'description' => $report->description,
                    'reportType' => $report->report_type->value,
                    'frequency' => $report->schedule_config_json['frequency'] ?? null,
                ])
                ->all(),
            'executions' => fn () => ReportExecution::query()
                ->where('team_id', $current_team->id)
                ->with('definition')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (ReportExecution $execution): array => [
                    'id' => (int) $execution->id,
                    'reportId' => (int) $execution->report_definition_id,
                    'reportName' => $execution->definition?->name,
                    'status' => $execution->status?->value,
                    'format' => $execution->output_format->value,
                    'error' => $execution->error_message,
                    'requestedAt' => ($execution->started_at ?? $execution->created_at)?->toIso8601String(),
                    'finishedAt' => $execution->finished_at?->toIso8601String(),
                    'automatic' => $execution->requested_by_type === ReportRequestedByType::Scheduler,
                    'downloadable' => $execution->status?->value === 'completed'
                        && $execution->file_path !== null,
                ])
                ->all(),
            'formats' => fn () => array_map(fn (ReportOutputFormat $format) => $format->value, ReportOutputFormat::cases()),
            'canGenerate' => fn () => (bool) request()->user()?->can('viewAny', ReportDefinition::class),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function metrics(int $teamId, CarbonInterface $from, CarbonInterface $previousFrom): array
    {
        $records = KpiRecord::query()
            ->where('team_id', $teamId)
            ->where('period_type', PeriodType::Daily->value)
            ->whereNull('dimension_type')
            ->where('period_start', '>=', $previousFrom)
            ->orderBy('period_start')
            ->get(['kpi_code', 'value', 'unit', 'period_start']);

        // Recalculations can leave several rows per day: keep the last.
        /** @var Collection<string, Collection<string, KpiRecord>> $byCode */
        $byCode = $records
            ->groupBy('kpi_code')
            ->map(fn (Collection $rows): Collection => $rows
                ->keyBy(fn (KpiRecord $row) => $row->period_start?->toDateString()));

        $fromDate = $from->toDateString();

        // Platform catalog (no team_id): human names for the seeded metrics.
        $names = MetricDefinition::query()->pluck('name', 'code');

        return $byCode
            ->map(function (Collection $daily, string $code) use ($names, $byCode, $fromDate): ?array {
                $current = $daily->filter(fn (KpiRecord $row, string $date): bool => $date >= $fromDate);
                $previous = $daily->filter(fn (KpiRecord $row, string $date): bool => $date < $fromDate);

                if ($current->isEmpty()) {
                    return null;
                }

                $unit = $current->last()->unit;
                $weights = isset(self::WEIGHTS[$code]) ? $byCode->get(self::WEIGHTS[$code]) : null;
                $aggregation = match (true) {
                    in_array($code, self::LEVEL_METRICS, true) => 'latest',
                    $unit === 'count' => 'sum',
                    default => 'avg',
                };

                $current = $this->withoutEmptyDays($current, $weights);
                $previous = $this->withoutEmptyDays($previous, $weights);

                return [
                    'code' => $code,
                    'name' => $names[$code] ?? null,
                    'group' => $this->groupFor($code),
                    'unit' => $unit,
                    'aggregation' => $aggregation,
                    'value' => $this->aggregate($current, $aggregation, $weights),
                    'previous' => $this->aggregate($previous, $aggregation, $weights),
                    'series' => $current
                        ->map(fn (KpiRecord $row, string $date): array => [
                            'date' => $date,
                            'value' => (float) $row->value,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->sortBy(fn (array $metric): string => array_search($metric['group'], self::GROUPS, true).'|'.$metric['code'])
            ->values()
            ->all();
    }

    /**
     * Days whose weighting count is 0 (no incident resolved, no decision, no
     * evaluation) have no rate or average to report: drop them.
     *
     * @param  Collection<string, KpiRecord>  $daily
     * @param  Collection<string, KpiRecord>|null  $weights
     * @return Collection<string, KpiRecord>
     */
    private function withoutEmptyDays(Collection $daily, ?Collection $weights): Collection
    {
        if ($weights === null) {
            return $daily;
        }

        return $daily->filter(fn (KpiRecord $row, string $date): bool => $this->weightOn($weights, $date) > 0);
    }

    /**
     * @param  Collection<string, KpiRecord>  $daily
     * @param  Collection<string, KpiRecord>|null  $weights
     */
    private function aggregate(Collection $daily, string $aggregation, ?Collection $weights): ?float
    {
        if ($daily->isEmpty()) {
            return null;
        }

        $values = $daily->map(fn (KpiRecord $row): float => (float) $row->value);

        return match ($aggregation) {
            'latest' => $values->last(),
            'sum' => $values->sum(),
            default => $weights === null
                ? round((float) $values->avg(), 4)
                : round(
                    $daily->sum(fn (KpiRecord $row, string $date): float => (float) $row->value * $this->weightOn($weights, $date))
                        / $daily->keys()->sum(fn (string $date): float => $this->weightOn($weights, $date)),
                    4,
                ),
        };
    }

    /**
     * @param  Collection<string, KpiRecord>  $weights
     */
    private function weightOn(Collection $weights, string $date): float
    {
        return (float) ($weights->get($date)?->value ?? 0);
    }

    private function groupFor(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'incidents_') => 'incidents',
            str_starts_with($code, 'ai_') => 'ai',
            str_starts_with($code, 'decisions_') => 'decisions',
            default => 'usage',
        };
    }
}
