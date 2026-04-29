<?php

namespace App\Domains\Context\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GetRelatedOpenIncidents
{
    /**
     * Returns open (non-terminal) incidents for the same tenant that involve either the
     * event's asset, driver, or that were already linked to this normalized event,
     * within a configurable look-back window. Used by the Context domain to power
     * `signals_json.has_open_incident` and the related-incidents snapshot.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(NormalizedEvent $normalizedEvent): Collection
    {
        $teamId = $normalizedEvent->team_id;

        if ($teamId === null) {
            return collect();
        }

        $window = (int) config('incidents.context_lookback_minutes', 240);
        $occurredAt = $normalizedEvent->occurred_at ?? now();
        $threshold = Carbon::instance($occurredAt)->subMinutes($window);

        $query = Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->where('opened_at', '>=', $threshold);

        $query->where(function ($q) use ($normalizedEvent) {
            $matched = false;

            if ($normalizedEvent->asset_id !== null) {
                $q->orWhere('asset_id', $normalizedEvent->asset_id);
                $matched = true;
            }

            if ($normalizedEvent->driver_id !== null) {
                $q->orWhere('driver_id', $normalizedEvent->driver_id);
                $matched = true;
            }

            $q->orWhereHas(
                'eventLinks',
                fn ($linkQuery) => $linkQuery->where('normalized_event_id', $normalizedEvent->id),
            );

            if (! $matched) {
                $q->orWhereRaw('1 = 0');
            }
        });

        return $query
            ->with(['type', 'status', 'priority'])
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get()
            ->map(fn (Incident $incident) => [
                'incident_id' => $incident->id,
                'title' => $incident->title,
                'type_code' => $incident->type?->code,
                'status_code' => $incident->status?->code,
                'priority_code' => $incident->priority?->code,
                'opened_at' => $incident->opened_at?->toIso8601String(),
                'asset_id' => $incident->asset_id,
                'driver_id' => $incident->driver_id,
            ]);
    }
}
