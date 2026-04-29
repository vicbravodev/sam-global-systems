<?php

namespace App\Contracts\Decisions;

use Carbon\CarbonInterface;

/**
 * Reads aggregate decision outcomes for a tenant in a window. Until the
 * Decisions domain ships a DB-backed query implementation, this contract is
 * fulfilled by `NullDecisionMetricsQuery` returning zeros so Analytics can
 * compute AI-effectiveness metrics with safe defaults.
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
