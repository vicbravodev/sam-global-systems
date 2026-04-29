<?php

namespace App\Contracts\Incidents;

use Carbon\CarbonInterface;

/**
 * SPEC-11-DEFERRED: Reads aggregate incident metrics for a tenant in a window.
 *
 * Implemented by the Incidents domain (spec 11) once it lands. Until then a
 * Null implementation returns zeros so Analytics can ship in isolation.
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
