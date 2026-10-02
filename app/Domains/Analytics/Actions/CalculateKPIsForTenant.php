<?php

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Events\KPIsCalculated;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Support\SystemLog;
use Carbon\CarbonInterface;

class CalculateKPIsForTenant
{
    public function __construct(
        private CalculateKPI $calculate,
        private EvaluateAIEffectiveness $evaluateAI,
    ) {}

    /**
     * Calculate every active metric for a single tenant + period and dispatch
     * the `KPIsCalculated` domain event.
     */
    public function execute(int $teamId, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        $metrics = MetricDefinition::query()->where('is_active', true)->get();

        foreach ($metrics as $metric) {
            $this->calculate->execute(
                $metric,
                $teamId,
                PeriodType::Daily,
                $periodStart,
                $periodEnd,
            );
        }

        $aiRecords = $this->evaluateAI->execute($teamId, $periodStart, $periodEnd);

        $totalCount = $metrics->count() + count($aiRecords);

        SystemLog::ok('analytics.kpis.calculated', input: [
            'team_id' => $teamId,
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
        ], result: [
            'metrics_count' => $metrics->count(),
            'ai_effectiveness_records' => count($aiRecords),
            'total_count' => $totalCount,
        ]);

        KPIsCalculated::dispatch(
            $teamId,
            $periodStart->toIso8601String(),
            $periodEnd->toIso8601String(),
            $totalCount,
        );

        return $totalCount;
    }
}
