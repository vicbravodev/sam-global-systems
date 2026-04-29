<?php

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Events\KPIsCalculated;
use App\Domains\Analytics\Models\MetricDefinition;
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

        KPIsCalculated::dispatch(
            $teamId,
            $periodStart->toIso8601String(),
            $periodEnd->toIso8601String(),
            $totalCount,
        );

        return $totalCount;
    }
}
