<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Incidents\IncidentMetricsQuery;
use Carbon\CarbonInterface;

/**
 * SPEC-11-DEFERRED stand-in: returns zero counts so Analytics can build
 * snapshots and KPIs without the Incidents domain present. The real
 * implementation lands with spec 11.
 */
class NullIncidentMetricsQuery implements IncidentMetricsQuery
{
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'total' => 0,
            'resolved' => 0,
            'open' => 0,
            'mean_resolution_time_minutes' => 0.0,
            'escalations' => 0,
        ];
    }
}
