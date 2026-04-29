<?php

namespace App\Contracts\Incidents;

use Carbon\CarbonInterface;

/**
 * Reads aggregate incident metrics for a tenant in a window. Until the
 * Incidents domain ships a DB-backed query implementation, this contract is
 * fulfilled by `NullIncidentMetricsQuery` returning zeros.
 */
interface IncidentMetricsQuery
{
    /**
     * @return array{
     *     total: int,
     *     resolved: int,
     *     open: int,
     *     mean_resolution_time_minutes: float,
     *     escalations: int,
     * }
     */
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array;
}
