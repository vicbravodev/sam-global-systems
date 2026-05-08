<?php

namespace App\Domains\Decisions\Queries;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use Carbon\CarbonInterface;

class DbDecisionMetricsQuery implements DecisionMetricsQuery
{
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = Decision::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('decided_at', [$from, $to]);

        $total = (int) (clone $base)->count();
        $humanReviewed = (int) (clone $base)->where('requires_human_review', true)->count();
        $autoResolved = (int) (clone $base)->where('is_automated', true)->count();

        $humanOverrides = (int) DecisionOverride::query()
            ->whereIn(
                'decision_id',
                Decision::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->whereBetween('decided_at', [$from, $to])
                    ->select('id'),
            )
            ->count();

        return [
            'total' => $total,
            'human_reviewed' => $humanReviewed,
            'human_overrides' => $humanOverrides,
            'auto_resolved' => $autoResolved,
        ];
    }
}
