<?php

namespace App\Domains\Context\Actions;

use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Collection;

class GetRelatedOpenIncidents
{
    /**
     * SPEC-11-DEFERRED: The Incidents domain (spec 11) is not yet implemented.
     * Until the `incidents` table and `EventRelatedIncidentLink` model land,
     * this action returns an empty collection so callers can safely compose
     * context without branching on spec availability.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(NormalizedEvent $normalizedEvent): Collection
    {
        unset($normalizedEvent);

        return collect();
    }
}
