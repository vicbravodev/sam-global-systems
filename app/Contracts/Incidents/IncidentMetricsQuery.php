<?php

namespace App\Contracts\Incidents;

use Carbon\CarbonInterface;

/**
 * Reads aggregate incident metrics for a tenant in a window. Backed by
 * `App\Domains\Incidents\Queries\DbIncidentMetricsQuery`.
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
