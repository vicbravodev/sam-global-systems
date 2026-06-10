<?php

namespace App\Domains\Normalization\Queries;

use App\Contracts\Normalization\NormalizedEventStatsQuery;
use App\Domains\Normalization\Models\NormalizedEvent;
use Carbon\CarbonInterface;

class DbNormalizedEventStatsQuery implements NormalizedEventStatsQuery
{
    public function countByProviderSince(int $teamId, CarbonInterface $since): array
    {
        return NormalizedEvent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('provider_id, COUNT(*) AS total')
            ->groupBy('provider_id')
            ->pluck('total', 'provider_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
