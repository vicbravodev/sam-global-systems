<?php

namespace App\Domains\Context\Actions;

use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GetPriorSimilarIncidents
{
    /**
     * Returns closed (terminal-status) incidents for the same tenant that involve
     * the event's asset or driver within a look-back window (default 7 days).
     * Powers `PriorSimilarIncident` related-incident links and the context
     * snapshot so the AI can see e.g. "third panic from this truck this week".
     * Visibility only — never merges incidents (Roadmap B6-P8).
     *
     * Incidents already linked to this normalized event are excluded so the
     * incident created by this very event never shows up as its own history.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(NormalizedEvent $normalizedEvent): Collection
    {
        $teamId = $normalizedEvent->team_id;

        if ($teamId === null || ($normalizedEvent->asset_id === null && $normalizedEvent->driver_id === null)) {
            return collect();
        }

        $days = (int) config('incidents.context_prior_lookback_days', 7);
        $occurredAt = Carbon::instance($normalizedEvent->occurred_at ?? now());
        $threshold = $occurredAt->copy()->subDays($days);

        return Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', true))
            ->whereBetween('opened_at', [$threshold, $occurredAt])
            ->whereDoesntHave(
                'eventLinks',
                fn ($linkQuery) => $linkQuery->where('normalized_event_id', $normalizedEvent->id),
            )
            ->where(function ($q) use ($normalizedEvent) {
                if ($normalizedEvent->asset_id !== null) {
                    $q->orWhere('asset_id', $normalizedEvent->asset_id);
                }

                if ($normalizedEvent->driver_id !== null) {
                    $q->orWhere('driver_id', $normalizedEvent->driver_id);
                }
            })
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
                'closed_at' => $incident->closed_at?->toIso8601String(),
                'asset_id' => $incident->asset_id,
                'driver_id' => $incident->driver_id,
                'relation' => IncidentRelationType::PriorSimilarIncident->value,
            ]);
    }
}
