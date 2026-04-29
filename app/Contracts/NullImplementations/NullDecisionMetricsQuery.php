<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Decisions\DecisionMetricsQuery;
use Carbon\CarbonInterface;

/**
 * Returns zero decision counts so AI-effectiveness KPIs degrade gracefully
 * (override rate = 0/0 → 0). Replaced by a DB-backed query when one ships.
 */
class NullDecisionMetricsQuery implements DecisionMetricsQuery
{
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'total' => 0,
            'human_reviewed' => 0,
            'human_overrides' => 0,
            'auto_resolved' => 0,
        ];
    }
}
