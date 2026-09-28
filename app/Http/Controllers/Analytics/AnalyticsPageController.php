<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Http\Controllers\Controller;
use App\Models\Team;
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

    public function show(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', KpiRecord::class);

        $period = in_array((int) $request->query('period'), self::PERIODS, true)
            ? (int) $request->query('period')
            : self::DEFAULT_PERIOD;

        return Inertia::render('analytics/index', [
            'period' => $period,
            'periods' => self::PERIODS,
            'overview' => function () use ($current_team): ?array {
                $snapshot = AnalyticsSnapshot::query()
                    ->where('team_id', $current_team->id)
                    ->where('snapshot_type', SnapshotType::TenantOverview->value)
                    ->orderByDesc('period_start')
                    ->first();

                if ($snapshot === null) {
                    return null;
                }

                return [
                    'periodStart' => $snapshot->period_start?->toIso8601String(),
                    'periodEnd' => $snapshot->period_end?->toIso8601String(),
                    'data' => $snapshot->snapshot_json,
                ];
            },
            // Daily tenant-wide KPIs of the selected window, one row per metric
            // with its period value and daily series (for the trend chart).
            'metrics' => fn (): array => $this->metrics($current_team->id, $period),
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
                    'reportType' => $report->report_type?->value ?? (string) $report->report_type,
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
                    'reportName' => $execution->definition?->name,
                    'status' => $execution->status?->value,
                    'format' => $execution->output_format?->value ?? (string) $execution->output_format,
                    'error' => $execution->error_message,
                    'finishedAt' => $execution->finished_at?->toIso8601String(),
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
    private function metrics(int $teamId, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $records = KpiRecord::query()
            ->where('team_id', $teamId)
            ->where('period_type', PeriodType::Daily->value)
            ->whereNull('dimension_type')
            ->where('period_start', '>=', $from)
            ->orderBy('period_start')
            ->get(['kpi_code', 'value', 'unit', 'period_start']);

        // Platform catalog (no team_id): human names for the seeded metrics.
        $names = MetricDefinition::query()->pluck('name', 'code');

        return $records
            ->groupBy('kpi_code')
            ->map(function (Collection $rows, string $code) use ($names): array {
                // Recalculations can leave several rows per day: keep the last.
                $daily = $rows->keyBy(fn (KpiRecord $row) => $row->period_start?->toDateString());
                $unit = $rows->last()->unit;
                $values = $daily->pluck('value')->map(fn ($value) => (float) $value);
                $aggregation = match (true) {
                    in_array($code, self::LEVEL_METRICS, true) => 'latest',
                    $unit === 'count' => 'sum',
                    default => 'avg',
                };

                return [
                    'code' => $code,
                    'name' => $names[$code] ?? null,
                    'group' => $this->groupFor($code),
                    'unit' => $unit,
                    'aggregation' => $aggregation,
                    'value' => match ($aggregation) {
                        'latest' => $values->last(),
                        'sum' => $values->sum(),
                        default => round((float) $values->avg(), 4),
                    },
                    'series' => $daily
                        ->map(fn (KpiRecord $row, string $date): array => [
                            'date' => $date,
                            'value' => (float) $row->value,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $metric): string => array_search($metric['group'], self::GROUPS, true).'|'.$metric['code'])
            ->values()
            ->all();
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
