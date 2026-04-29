<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Incidents\IncidentMetricsQuery;
use Carbon\CarbonInterface;

/**
 * Returns zero incident counts so Analytics snapshots and KPIs degrade
 * gracefully when no DB-backed query implementation is wired in.
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
