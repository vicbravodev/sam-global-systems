<?php

namespace Database\Seeders\Showcase;

use App\Domains\Analytics\Actions\BuildAnalyticsSnapshot;
use App\Domains\Analytics\Actions\CalculateKPIsForTenant;
use App\Domains\Analytics\Actions\GenerateReport;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Jobs\RebuildHistoricalMetricsJob;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use Throwable;

/**
 * Analítica calculada con el código real, no inventada: KPIs diarios de toda
 * la ventana con {@see RebuildHistoricalMetricsJob}, snapshots diarios de
 * los 4 tipos con contenido ({@see BuildAnalyticsSnapshot}) y ejecuciones
 * de reporte generadas con {@see GenerateReport} (dashboard, y los formatos
 * con archivo si el storage responde), más un par de ejecuciones históricas
 * fallida / expirada / en curso.
 *
 * Los KPIs y snapshots son derivados (upsert por periodo): recalcularlos en
 * cada corrida es idempotente. Las ejecuciones sólo se crean si el tenant no
 * tiene ninguna marcada con `filters_json.showcase`.
 */
class AnalyticsShowcaseSeeder extends ShowcaseStep
{
    private const SNAPSHOT_TYPES = [
        SnapshotType::TenantOverview,
        SnapshotType::OperationalSummary,
        SnapshotType::AiPerformance,
        SnapshotType::AssetRiskProfile,
    ];

    public function run(): void
    {
        $teamId = $this->ctx->team->id;
        $from = $this->ctx->startDay();
        $to = $this->ctx->now->subDay()->startOfDay();

        (new RebuildHistoricalMetricsJob($teamId, $from->toDateString(), $to->toDateString()))
            ->handle(app(CalculateKPIsForTenant::class));
        $this->ctx->count('kpi_records (recalculados, días)', $this->ctx->days);

        $snapshots = app(BuildAnalyticsSnapshot::class);

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach (self::SNAPSHOT_TYPES as $type) {
                $snapshots->execute($teamId, $type, $day->startOfDay(), $day->endOfDay());
            }
        }

        $this->ctx->count('analytics_snapshots (recalculados)', $this->ctx->days * count(self::SNAPSHOT_TYPES));

        $this->seedTenantReports();

        if (! $this->ctx->light) {
            $this->seedExecutions();
            // GenerateReport factura `generated_reports`: re-agregar para que el
            // contador del mes ya lo incluya (y una segunda corrida no cambie nada).
            (new AggregateUsageJob($teamId))->handle();
        }
    }

    private function seedTenantReports(): void
    {
        $exists = ReportDefinition::query()->where('team_id', $this->ctx->team->id)->where('code', 'cliente-semanal')->exists();

        if ($exists) {
            return;
        }

        ReportDefinition::query()->create([
            'team_id' => $this->ctx->team->id,
            'code' => 'cliente-semanal',
            'name' => 'Reporte semanal para cliente',
            'description' => 'Incidentes y tiempos de atención del cliente principal, cada lunes.',
            'report_type' => 'custom',
            'data_sources_json' => ['kpi_records'],
            'filters_schema_json' => ['period' => ['weekly']],
            'metrics_json' => ['incidents_total', 'incidents_resolved', 'incidents_mttr_minutes'],
            'visualization_config_json' => ['layout' => 'table'],
            'schedule_config_json' => ['frequency' => 'weekly', 'day_of_week' => 'monday', 'time' => '08:00', 'timezone' => 'America/Mexico_City'],
            'is_active' => true,
        ]);
        $this->ctx->count('report_definitions');
    }

    private function seedExecutions(): void
    {
        $teamId = $this->ctx->team->id;
        $already = ReportExecution::query()->where('team_id', $teamId)->whereNotNull('filters_json->showcase')->exists();

        if ($already) {
            return;
        }

        $definitions = ReportDefinition::query()
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
            ->where('is_active', true)
            ->get()
            ->keyBy('code');
        $generate = app(GenerateReport::class);
        $analyst = $this->ctx->users['analyst'] ?? $this->ctx->user('admin');

        $plan = [
            ['operational_daily', ReportOutputFormat::Dashboard, 1],
            ['ai_performance', ReportOutputFormat::Dashboard, 3],
            ['executive_monthly', ReportOutputFormat::Pdf, 6],
            ['sla_compliance', ReportOutputFormat::Xlsx, 9],
            ['incident_analysis', ReportOutputFormat::Csv, 14],
        ];

        foreach ($plan as [$code, $format, $daysAgo]) {
            $definition = $definitions->get($code);

            if ($definition === null) {
                continue;
            }

            try {
                $execution = $generate->execute($definition, $teamId, $format, ReportRequestedByType::User, $analyst->id, ['showcase' => true, 'period' => 'last_30_days']);
            } catch (Throwable) {
                // GenerateReport ya dejó la ejecución en `failed` con el error real
                // (típicamente: storage no accesible desde este proceso).
                $execution = ReportExecution::query()->where('team_id', $teamId)->latest('id')->first();
            }

            $at = $this->ctx->now->subDays($daysAgo)->setTime(8, 5);
            $execution?->forceFill(['started_at' => $at, 'finished_at' => $at->addSeconds(4), 'created_at' => $at])->save();
            $this->ctx->count('report_executions');
        }

        foreach ($this->executionHistory() as [$code, $status, $format, $daysAgo, $error]) {
            $definition = $definitions->get($code);

            if ($definition === null) {
                continue;
            }

            $at = $this->ctx->now->subDays(min($daysAgo, $this->ctx->days - 1))->setTime(8, 0);
            ReportExecution::query()->create([
                'report_definition_id' => $definition->id,
                'team_id' => $teamId,
                'requested_by_type' => $status === 'running' ? 'scheduler' : 'user',
                'requested_by_id' => $status === 'running' ? null : $analyst->id,
                'filters_json' => ['showcase' => true, 'period' => 'last_7_days'],
                'status' => $status,
                'output_format' => $format,
                'file_path' => null,
                'result_snapshot_json' => null,
                'error_message' => $error,
                'started_at' => $status === 'running' ? $this->ctx->now->subMinutes(2) : $at,
                'finished_at' => $status === 'running' ? null : $at->addSeconds(61),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $this->ctx->count('report_executions');
        }
    }

    /**
     * Ejecuciones históricas que no pasan por GenerateReport: código de
     * definición, estado, formato, días atrás y error.
     *
     * @return list<array{string, string, string, int, string|null}>
     */
    private function executionHistory(): array
    {
        return [
            ['executive_monthly', 'failed', 'pdf', 20, 'Timeout generando el PDF: la consulta de KPIs tardó más de 60 s.'],
            ['asset_risk', 'expired', 'xlsx', 40, null],
            ['cliente-semanal', 'running', 'pdf', 0, null],
        ];
    }
}
