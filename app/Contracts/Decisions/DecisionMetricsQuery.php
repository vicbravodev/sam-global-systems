<?php

namespace App\Contracts\Decisions;

use Carbon\CarbonInterface;

/**
 * Reads aggregate decision outcomes for a tenant in a window. Backed by
 * `App\Domains\Decisions\Queries\DbDecisionMetricsQuery` so Analytics can
 * compute AI-effectiveness metrics from the live data.
 */
interface DecisionMetricsQuery
{
    /**
     * @return array{
     *     total: int,
     *     human_reviewed: int,
     *     human_overrides: int,
     *     auto_resolved: int,
     * }
     */
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array;
}
