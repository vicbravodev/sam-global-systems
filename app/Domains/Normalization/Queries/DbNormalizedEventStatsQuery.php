<?php

namespace App\Domains\Normalization\Queries;

use App\Contracts\Normalization\NormalizedEventStatsQuery;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\TenantContext;
use Carbon\CarbonInterface;

class DbNormalizedEventStatsQuery implements NormalizedEventStatsQuery
{
    public function countByProviderSince(int $teamId, CarbonInterface $since): array
    {
        return NormalizedEvent::query()
            ->where('team_id', $teamId)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('provider_id, COUNT(*) AS total')
            ->groupBy('provider_id')
            ->pluck('total', 'provider_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function countByIntegrationSince(int $teamId, CarbonInterface $since): array
    {
        return TenantContext::for($teamId, fn () => NormalizedEvent::query()
            ->join('raw_events', 'raw_events.id', '=', 'normalized_events.raw_event_id')
            ->join('event_sources', 'event_sources.id', '=', 'raw_events.event_source_id')
            // Every joined row must belong to the same tenant: an id collision
            // across tenants can never attribute events to a foreign integration.
            ->where('normalized_events.team_id', $teamId)
            ->where('raw_events.team_id', $teamId)
            ->where('event_sources.team_id', $teamId)
            ->whereNotNull('event_sources.tenant_integration_id')
            ->where('normalized_events.occurred_at', '>=', $since)
            ->selectRaw('event_sources.tenant_integration_id AS integration_id, COUNT(*) AS total')
            ->groupBy('event_sources.tenant_integration_id')
            ->pluck('total', 'integration_id')
            ->mapWithKeys(fn ($count, $integrationId) => [(int) $integrationId => (int) $count])
            ->all());
    }
}
