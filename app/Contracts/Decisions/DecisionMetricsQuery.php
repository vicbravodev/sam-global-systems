<?php

namespace App\Contracts\Decisions;

use Carbon\CarbonInterface;

/**
 * SPEC-10-DEFERRED: Reads aggregate decision outcomes for a tenant in a window.
 *
 * Implemented by the Decisions domain (spec 10) once it lands. Until then a
 * Null implementation returns zeros so Analytics can compute AI effectiveness
 * metrics with safe defaults (e.g. zero overrides → no human review pressure).
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
