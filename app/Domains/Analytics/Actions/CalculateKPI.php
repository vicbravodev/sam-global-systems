<?php

namespace App\Domains\Analytics\Actions;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Analytics\Enums\DimensionType;
use App\Domains\Analytics\Enums\MetricAggregationType;
use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use Carbon\CarbonInterface;

class CalculateKPI
{
    public function __construct(
        private IncidentMetricsQuery $incidents,
        private DecisionMetricsQuery $decisions,
    ) {}

    public function execute(
        MetricDefinition $metric,
        int $teamId,
        PeriodType $period,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?DimensionType $dimensionType = null,
        ?string $dimensionReference = null,
    ): KpiRecord {
        $value = $this->computeValue(
            $metric,
            $teamId,
            $periodStart,
            $periodEnd,
            $dimensionType,
            $dimensionReference,
        );

        $query = KpiRecord::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('kpi_code', $metric->code)
            ->where('period_type', $period->value)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);

        $dimensionType !== null
            ? $query->where('dimension_type', $dimensionType->value)
            : $query->whereNull('dimension_type');

        $dimensionReference !== null
            ? $query->where('dimension_reference', $dimensionReference)
            : $query->whereNull('dimension_reference');

        $record = $query->first();

        $payload = [
            'team_id' => $teamId,
            'kpi_code' => $metric->code,
            'period_type' => $period->value,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'dimension_type' => $dimensionType?->value,
            'dimension_reference' => $dimensionReference,
            'value' => $value,
            'unit' => $metric->unit,
            'metadata_json' => null,
            'calculated_at' => now(),
        ];

        if ($record) {
            $record->forceFill($payload)->save();

            return $record;
        }

        return KpiRecord::withoutGlobalScopes()->create($payload);
    }

    private function computeValue(
        MetricDefinition $metric,
        int $teamId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?DimensionType $dimensionType,
        ?string $dimensionReference,
    ): float {
        return match ($metric->code) {
            'incidents_total' => (float) $this->incidents->totalsForTenant($teamId, $from, $to)['total'],
            'incidents_resolved' => (float) $this->incidents->totalsForTenant($teamId, $from, $to)['resolved'],
            'incidents_open' => (float) $this->incidents->totalsForTenant($teamId, $from, $to)['open'],
            'incidents_mttr_minutes' => (float) $this->incidents->totalsForTenant($teamId, $from, $to)['mean_resolution_time_minutes'],
            'decisions_total' => (float) $this->decisions->totalsForTenant($teamId, $from, $to)['total'],
            'decisions_human_review_rate' => $this->humanReviewRate($teamId, $from, $to),
            'ai_evaluations_total' => $this->aiEvaluationsCount($teamId, $from, $to, $dimensionType, $dimensionReference),
            'ai_average_confidence' => $this->aiAverageConfidence($teamId, $from, $to),
            'active_assets' => $this->activeAssetsCount($teamId),
            default => $this->computeFromUsageMeter($metric, $teamId, $from, $to),
        };
    }

    private function aiEvaluationsCount(
        int $teamId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?DimensionType $dimensionType,
        ?string $dimensionReference,
    ): float {
        $query = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to]);

        if ($dimensionType === DimensionType::EventType && $dimensionReference !== null) {
            $query->where('classification', $dimensionReference);
        }

        return (float) $query->count();
    }

    private function aiAverageConfidence(int $teamId, CarbonInterface $from, CarbonInterface $to): float
    {
        $avg = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->whereNotNull('confidence_score')
            ->avg('confidence_score');

        return (float) ($avg ?? 0);
    }

    private function humanReviewRate(int $teamId, CarbonInterface $from, CarbonInterface $to): float
    {
        $totals = $this->decisions->totalsForTenant($teamId, $from, $to);
        $total = (int) $totals['total'];

        if ($total === 0) {
            return 0.0;
        }

        return round((float) $totals['human_reviewed'] / $total, 4);
    }

    private function activeAssetsCount(int $teamId): float
    {
        return (float) Asset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->count();
    }

    private function computeFromUsageMeter(
        MetricDefinition $metric,
        int $teamId,
        CarbonInterface $from,
        CarbonInterface $to,
    ): float {
        $meter = UsageMeter::query()->where('code', $metric->code)->first();

        if (! $meter) {
            return 0.0;
        }

        $query = UsageEvent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('usage_meter_id', $meter->id)
            ->whereBetween('occurred_at', [$from, $to]);

        return match ($metric->aggregation_type) {
            MetricAggregationType::Sum => (float) $query->sum('quantity'),
            MetricAggregationType::Avg => (float) ($query->avg('quantity') ?? 0),
            MetricAggregationType::Max => (float) ($query->max('quantity') ?? 0),
            MetricAggregationType::Min => (float) ($query->min('quantity') ?? 0),
            MetricAggregationType::Count => (float) $query->count(),
            MetricAggregationType::Rate => $this->computeRate($query, $from, $to),
        };
    }

    private function computeRate($query, CarbonInterface $from, CarbonInterface $to): float
    {
        $total = (float) $query->sum('quantity');
        $hours = max(1, $from->diffInHours($to));

        return round($total / $hours, 4);
    }
}
