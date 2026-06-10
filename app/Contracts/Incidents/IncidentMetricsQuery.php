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

    /**
     * Incidents currently open (non-terminal status), regardless of window.
     *
     * @return array{open: int, critical_open: int}
     */
    public function openCounts(int $teamId): array;

    /**
     * Incidents opened per calendar day in the window, with empty days
     * filled with zeroes. One bucket per day, oldest first.
     *
     * @return list<array{date: string, total: int, critical: int}>
     */
    public function openedPerDay(int $teamId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Percentage (0-100) of incidents resolved in the window whose
     * resolution time stayed within their SLA. Null when nothing resolved.
     */
    public function slaCompliance(int $teamId, CarbonInterface $from, CarbonInterface $to): ?float;
}
