<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;

/**
 * Finds the incident an escalated episode ended up in and stores it on the
 * episode: its raw event (`hos:{episode}`) → the normalized event → the
 * incident that event opened (`related_event_id`) or was folded into
 * (`incident_event_links`, CreateIncidentFromEvent dedup). Every step is
 * filtered by the episode's team; `attachIncident` checks it again on write.
 * Until the pipeline finishes there is nothing yet: retried every minute.
 *
 * Must run inside the episode's TenantContext.
 */
class LinkHosEpisodeIncident
{
    public function execute(HosEpisode $episode): ?Incident
    {
        $teamId = $episode->team_id;

        if ($episode->incident_id !== null) {
            return Incident::query()->where('team_id', $teamId)->find($episode->incident_id);
        }

        $input = ['team_id' => $teamId, 'episode_id' => $episode->id];

        $rawEventId = RawEvent::query()
            ->where('team_id', $teamId)
            ->where('deduplication_key', RaiseHosIncident::deduplicationKey($episode))
            ->value('id');

        $eventId = is_numeric($rawEventId)
            ? NormalizedEvent::query()->where('team_id', $teamId)->where('raw_event_id', (int) $rawEventId)->value('id')
            : null;

        $incident = is_numeric($eventId)
            ? Incident::query()
                ->where('team_id', $teamId)
                ->where(fn ($query) => $query
                    ->where('related_event_id', (int) $eventId)
                    ->orWhereHas('eventLinks', fn ($links) => $links->where('normalized_event_id', (int) $eventId)))
                ->orderByDesc('id')
                ->first()
            : null;

        if ($incident === null) {
            SystemLog::skipped('hos.incident.linked', reason: 'event_pending', input: $input, calc: [
                'raw_event_present' => $rawEventId !== null,
                'normalized_event_present' => $eventId !== null,
            ], debug: true);

            return null;
        }

        $episode->attachIncident($incident);

        SystemLog::ok('hos.incident.linked', input: $input, result: ['incident_id' => $incident->id]);

        return $incident;
    }
}
