<?php

namespace Database\Seeders;

use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Analytics\Models\ReportDefinition;
use Illuminate\Database\Seeder;

/**
 * Reportes globales de plataforma (`team_id = null`): los ve todo tenant en
 * Analítica y los puede generar en PDF/XLSX/CSV. `metrics_json` son códigos
 * de `metric_definitions` / KPIs de IA que `GenerateReport` vuelca.
 *
 * Idempotente: busca por `code` sólo entre las filas globales, así nunca
 * pisa un reporte propio de un tenant con el mismo código. Se siembra
 * también en producción.
 */
class ReportDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $reports = [
            [
                'code' => 'operational_daily',
                'name' => 'Resumen operativo diario',
                'description' => 'Incidentes abiertos y resueltos del día, cuánto se tardó en cerrarlos y cuántos eventos llegaron.',
                'report_type' => ReportType::Operational,
                'metrics' => ['incidents_total', 'incidents_resolved', 'incidents_open', 'incidents_mttr_minutes', 'ingested_events'],
                'schedule' => ['frequency' => 'daily', 'time' => '07:00', 'timezone' => 'America/Mexico_City'],
            ],
            [
                'code' => 'executive_monthly',
                'name' => 'Reporte ejecutivo mensual',
                'description' => 'Para dirección: incidentes, decisiones, unidades vigiladas y avisos enviados en el mes.',
                'report_type' => ReportType::Executive,
                'metrics' => ['incidents_total', 'incidents_mttr_minutes', 'decisions_total', 'active_assets', 'outbound_notifications'],
                'schedule' => ['frequency' => 'monthly', 'day_of_month' => 1, 'time' => '08:00', 'timezone' => 'America/Mexico_City'],
            ],
            [
                'code' => 'sla_compliance',
                'name' => 'Cumplimiento de SLA',
                'description' => 'Cuánto se tardó en resolver los incidentes frente al tiempo comprometido, por prioridad.',
                'report_type' => ReportType::Sla,
                'metrics' => ['incidents_mttr_minutes', 'incidents_resolved', 'incidents_open'],
                'schedule' => ['frequency' => 'weekly', 'day_of_week' => 'monday', 'time' => '08:00', 'timezone' => 'America/Mexico_City'],
            ],
            [
                'code' => 'ai_performance',
                'name' => 'Desempeño de la IA',
                'description' => 'Qué tanto acierta la IA, cuántas falsas alarmas filtra, qué tan segura está y cuántas veces la corrige una persona.',
                'report_type' => ReportType::AiPerformance,
                'metrics' => ['ai_total_evaluations', 'ai_accuracy_rate', 'ai_false_positive_rate', 'ai_average_confidence', 'ai_human_override_rate'],
                'schedule' => null,
            ],
            [
                'code' => 'incident_analysis',
                'name' => 'Análisis de incidentes',
                'description' => 'Evolución diaria de incidentes y decisiones que requirieron revisión humana.',
                'report_type' => ReportType::IncidentAnalysis,
                'metrics' => ['incidents_total', 'incidents_resolved', 'decisions_human_review_rate'],
                'schedule' => null,
            ],
            [
                'code' => 'asset_risk',
                'name' => 'Riesgo por activo',
                'description' => 'Unidades activas y cuántos eventos genera cada una: la base de su perfil de riesgo.',
                'report_type' => ReportType::AssetRisk,
                'metrics' => ['active_assets', 'ingested_events', 'ai_evaluations_total'],
                'schedule' => null,
            ],
        ];

        foreach ($reports as $report) {
            $attributes = [
                'name' => $report['name'],
                'description' => $report['description'],
                'report_type' => $report['report_type'],
                'data_sources_json' => ['kpi_records'],
                'filters_schema_json' => ['period' => ['daily', 'weekly', 'monthly']],
                'metrics_json' => $report['metrics'],
                'visualization_config_json' => ['layout' => 'table'],
                'schedule_config_json' => $report['schedule'],
                'is_active' => true,
            ];

            $existing = ReportDefinition::query()
                ->whereNull('team_id')
                ->where('code', $report['code'])
                ->first();

            $existing !== null
                ? $existing->update($attributes)
                : ReportDefinition::query()->create(['team_id' => null, 'code' => $report['code'], ...$attributes]);
        }
    }
}
