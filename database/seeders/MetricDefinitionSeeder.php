<?php

namespace Database\Seeders;

use App\Domains\Analytics\Enums\MetricAggregationType;
use App\Domains\Analytics\Models\MetricDefinition;
use Illuminate\Database\Seeder;

/**
 * Catálogo de plataforma de métricas KPI. `CalculateKPIsForTenant` sólo
 * calcula las definiciones activas: sin estas filas el job diario de KPIs
 * (y `RebuildHistoricalMetricsJob`) no escribe nada y Analítica queda vacía.
 *
 * Los códigos con cálculo dedicado están en `CalculateKPI::computeValue()`;
 * el resto (`ai_calls`, `ingested_events`, …) se calcula sumando los
 * `usage_events` del medidor con el mismo código.
 *
 * Idempotente (updateOrCreate por `code`). Se siembra también en producción.
 */
class MetricDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $metrics = [
            ['incidents_total', 'Incidentes abiertos en el periodo', 'count(incidents where opened_at in period)', 'count', MetricAggregationType::Count, ['incidents']],
            ['incidents_resolved', 'Incidentes resueltos', 'count(incidents resolved in period)', 'count', MetricAggregationType::Count, ['incidents']],
            ['incidents_open', 'Incidentes aún abiertos', 'count(incidents opened in period and not terminal)', 'count', MetricAggregationType::Count, ['incidents']],
            ['incidents_mttr_minutes', 'Tiempo medio de resolución', 'avg(resolved_at - opened_at) en minutos', 'minutes', MetricAggregationType::Avg, ['incidents']],
            ['decisions_total', 'Decisiones tomadas', 'count(decisions in period)', 'count', MetricAggregationType::Count, ['decisions']],
            ['decisions_human_review_rate', 'Tasa de revisión humana', 'decisions requiring human review / decisions', 'ratio', MetricAggregationType::Rate, ['decisions']],
            ['ai_evaluations_total', 'Evaluaciones de IA', 'count(ai_event_evaluations in period)', 'count', MetricAggregationType::Count, ['ai']],
            ['ai_average_confidence', 'Confianza media de la IA', 'avg(confidence_score)', 'score', MetricAggregationType::Avg, ['ai']],
            ['active_assets', 'Activos monitoreados', 'count(assets)', 'count', MetricAggregationType::Count, ['assets']],
            ['ingested_events', 'Eventos ingeridos', 'sum(usage_events ingested_events)', 'count', MetricAggregationType::Sum, ['ingestion', 'tenancy']],
            ['ai_calls', 'Llamadas a la IA', 'sum(usage_events ai_calls)', 'count', MetricAggregationType::Sum, ['ai', 'tenancy']],
            ['outbound_notifications', 'Notificaciones enviadas', 'sum(usage_events outbound_notifications)', 'count', MetricAggregationType::Sum, ['notifications', 'tenancy']],
            ['copilot_queries', 'Consultas a SAM Copilot', 'sum(usage_events copilot_queries)', 'count', MetricAggregationType::Sum, ['copilot', 'tenancy']],
        ];

        foreach ($metrics as [$code, $name, $formula, $unit, $aggregation, $modules]) {
            MetricDefinition::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'description' => $name.'.',
                    'formula_description' => $formula,
                    'unit' => $unit,
                    'aggregation_type' => $aggregation,
                    'source_modules_json' => $modules,
                    'is_active' => true,
                ],
            );
        }
    }
}
