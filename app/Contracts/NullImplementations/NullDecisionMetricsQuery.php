<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Decisions\DecisionMetricsQuery;
use Carbon\CarbonInterface;

/**
 * SPEC-10-DEFERRED stand-in: returns zero decision counts so AI effectiveness
 * KPIs degrade gracefully (override rate = 0/0 → 0). The real implementation
 * lands with spec 10.
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
