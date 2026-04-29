<?php

namespace App\Domains\Analytics\Actions;

use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use Carbon\CarbonInterface;

class BuildAnalyticsSnapshot
{
    public function __construct(
        private IncidentMetricsQuery $incidents,
    ) {}

    public function execute(
        int $teamId,
        SnapshotType $type,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): AnalyticsSnapshot {
        $payload = match ($type) {
            SnapshotType::TenantOverview => $this->tenantOverview($teamId, $periodStart, $periodEnd),
            SnapshotType::OperationalSummary => $this->operationalSummary($teamId, $periodStart, $periodEnd),
            SnapshotType::AiPerformance => $this->aiPerformance($teamId, $periodStart, $periodEnd),
            SnapshotType::AssetRiskProfile,
            SnapshotType::OperatorPerformance,
            SnapshotType::ZoneAnalysis => ['period' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ]],
        };

        $existing = AnalyticsSnapshot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('snapshot_type', $type->value)
            ->whereNull('entity_type')
            ->whereNull('entity_id')
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        $payload = [
            'team_id' => $teamId,
            'snapshot_type' => $type->value,
            'entity_type' => null,
            'entity_id' => null,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'snapshot_json' => $payload,
        ];

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return AnalyticsSnapshot::withoutGlobalScopes()->create($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantOverview(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = $this->incidents->totalsForTenant($teamId, $from, $to);

        $accuracy = $this->aiAccuracyRate($teamId, $from, $to);

        return [
            'total_incidents' => (int) $totals['total'],
            'resolved_incidents' => (int) $totals['resolved'],
            'mean_resolution_time_minutes' => (float) $totals['mean_resolution_time_minutes'],
            'ai_accuracy_rate' => $accuracy,
            'active_assets' => Asset::withoutGlobalScopes()->where('team_id', $teamId)->count(),
            'active_integrations' => TenantIntegration::withoutGlobalScopes()->where('team_id', $teamId)->count(),
            'usage_summary' => $this->usageSummary($teamId, $from, $to),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalSummary(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = $this->incidents->totalsForTenant($teamId, $from, $to);

        return [
            'incidents' => $totals,
            'usage_summary' => $this->usageSummary($teamId, $from, $to),
            'period' => [
                'start' => $from->toDateString(),
                'end' => $to->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiPerformance(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $totalEvaluations = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->count();

        $falsePositives = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->where('classification', EventClassification::FalsePositive->value)
            ->count();

        $avgConfidence = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->whereNotNull('confidence_score')
            ->avg('confidence_score');

        return [
            'total_evaluations' => $totalEvaluations,
            'false_positive_rate' => $totalEvaluations === 0 ? 0.0 : round($falsePositives / $totalEvaluations, 4),
            'average_confidence' => (float) ($avgConfidence ?? 0),
        ];
    }

    private function aiAccuracyRate(int $teamId, CarbonInterface $from, CarbonInterface $to): float
    {
        $total = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $real = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('evaluated_at', [$from, $to])
            ->where('classification', EventClassification::RealEvent->value)
            ->count();

        return round($real / $total, 4);
    }

    /**
     * @return array<string, int>
     */
    private function usageSummary(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = [];

        $meterCodes = ['api_requests', 'ai_calls', 'outbound_notifications'];
        $meters = UsageMeter::query()->whereIn('code', $meterCodes)->get();

        foreach ($meters as $meter) {
            $summary[$meter->code] = (int) UsageEvent::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('usage_meter_id', $meter->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->sum('quantity');
        }

        return $summary;
    }
}
