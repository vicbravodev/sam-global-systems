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

    /**
     * Events received per tenant integration since the given moment, via the
     * raw event's source (`event_sources.tenant_integration_id`). Two
     * integrations of the same provider get separate counts.
     *
     * @return array<int, int> tenant_integration_id => event count
     */
    public function countByIntegrationSince(int $teamId, CarbonInterface $since): array;
}
