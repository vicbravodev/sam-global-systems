<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analytics page (Roadmap F13): KPI dashboard + report definitions with
 * generation and PDF/XLSX download. Mutations reuse the Analytics API
 * controllers as web routes.
 */
class AnalyticsPageController extends Controller
{
    public function show(Team $current_team): Response
    {
        $this->authorize('viewAny', KpiRecord::class);

        return Inertia::render('analytics/index', [
            'overview' => function () use ($current_team): ?array {
                $snapshot = AnalyticsSnapshot::withoutGlobalScopes()
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
            'kpis' => fn () => KpiRecord::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderByDesc('calculated_at')
                ->limit(50)
                ->get()
                ->map(fn (KpiRecord $kpi): array => [
                    'id' => (int) $kpi->id,
                    'code' => $kpi->kpi_code,
                    'value' => $kpi->value !== null ? (float) $kpi->value : null,
                    'unit' => $kpi->unit,
                    'periodType' => $kpi->period_type?->value ?? (string) $kpi->period_type,
                    'periodStart' => $kpi->period_start?->toIso8601String(),
                    'dimensionType' => $kpi->dimension_type?->value ?? null,
                    'dimensionReference' => $kpi->dimension_reference,
                    'calculatedAt' => $kpi->calculated_at?->toIso8601String(),
                ])
                ->all(),
            'reports' => fn () => ReportDefinition::withoutGlobalScopes()
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
            'executions' => fn () => ReportExecution::withoutGlobalScopes()
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
}
