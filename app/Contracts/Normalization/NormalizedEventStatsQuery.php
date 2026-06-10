<?php

namespace App\Contracts\Normalization;

use Carbon\CarbonInterface;

/**
 * Reads aggregate normalized-event stats for a tenant. Backed by
 * `App\Domains\Normalization\Queries\DbNormalizedEventStatsQuery`.
 */
interface NormalizedEventStatsQuery
{
    /**
     * Events received per integration provider since the given moment.
     *
     * @return array<int, int> provider_id => event count
     */
    public function countByProviderSince(int $teamId, CarbonInterface $since): array;
}
