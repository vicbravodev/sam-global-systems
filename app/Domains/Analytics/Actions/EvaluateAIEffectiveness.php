<?php

namespace App\Domains\Analytics\Actions;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Models\KpiRecord;
use Carbon\CarbonInterface;

class EvaluateAIEffectiveness
{
    public function __construct(
        private DecisionMetricsQuery $decisions,
    ) {}

    /**
     * Calculate AI-effectiveness KPIs (accuracy, false-positive rate, average
     * confidence, human-override rate) and persist them as KPI records under
     * the `ai_*` namespace.
     *
     * @return array<string, KpiRecord>
     */
    public function execute(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $records = [];

        $totalEvaluations = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->count();

        $falsePositives = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->where('classification', EventClassification::FalsePositive->value)
            ->count();

        $realEvents = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->where('classification', EventClassification::RealEvent->value)
            ->count();

        $avgConfidence = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->whereNotNull('confidence_score')
            ->avg('confidence_score');

        $decisionTotals = $this->decisions->totalsForTenant($teamId, $from, $to);
        $decisionsCount = (int) $decisionTotals['total'];
        $overrides = (int) $decisionTotals['human_overrides'];

        $records['ai_total_evaluations'] = $this->upsert(
            $teamId,
            'ai_total_evaluations',
            $from,
            $to,
            (float) $totalEvaluations,
            'count',
        );

        $records['ai_false_positive_rate'] = $this->upsert(
            $teamId,
            'ai_false_positive_rate',
            $from,
            $to,
            $totalEvaluations === 0 ? 0.0 : round($falsePositives / $totalEvaluations, 4),
            'ratio',
        );

        $records['ai_real_event_rate'] = $this->upsert(
            $teamId,
            'ai_real_event_rate',
            $from,
            $to,
            $totalEvaluations === 0 ? 0.0 : round($realEvents / $totalEvaluations, 4),
            'ratio',
        );

        $records['ai_average_confidence'] = $this->upsert(
            $teamId,
            'ai_average_confidence',
            $from,
            $to,
            (float) ($avgConfidence ?? 0),
            'score',
        );

        $records['ai_human_override_rate'] = $this->upsert(
            $teamId,
            'ai_human_override_rate',
            $from,
            $to,
            $decisionsCount === 0 ? 0.0 : round($overrides / $decisionsCount, 4),
            'ratio',
        );

        $records['ai_accuracy_rate'] = $this->upsert(
            $teamId,
            'ai_accuracy_rate',
            $from,
            $to,
            $decisionsCount === 0 ? 0.0 : round(max(0, $decisionsCount - $overrides) / $decisionsCount, 4),
            'ratio',
        );

        return $records;
    }

    private function upsert(
        int $teamId,
        string $code,
        CarbonInterface $from,
        CarbonInterface $to,
        float $value,
        string $unit,
    ): KpiRecord {
        $record = KpiRecord::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('kpi_code', $code)
            ->where('period_type', PeriodType::Daily->value)
            ->where('period_start', $from)
            ->where('period_end', $to)
            ->whereNull('dimension_type')
            ->whereNull('dimension_reference')
            ->first();

        $payload = [
            'team_id' => $teamId,
            'kpi_code' => $code,
            'period_type' => PeriodType::Daily->value,
            'period_start' => $from,
            'period_end' => $to,
            'dimension_type' => null,
            'dimension_reference' => null,
            'value' => $value,
            'unit' => $unit,
            'metadata_json' => null,
            'calculated_at' => now(),
        ];

        if ($record) {
            $record->forceFill($payload)->save();

            return $record;
        }

        return KpiRecord::withoutGlobalScopes()->create($payload);
    }
}
