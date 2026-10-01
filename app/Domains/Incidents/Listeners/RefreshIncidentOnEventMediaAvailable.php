<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Panic footage lands a minute or two after the incident opened: the operator
 * already has the detail (or the inbox preview) on screen. Tell those screens
 * to reload as soon as the files exist, instead of waiting for the AI to
 * assess them — clips are never assessed directly, and a spent AI quota would
 * leave the photos invisible until a manual reload.
 *
 * One upload sweep materializes several files (photo + clip per camera, then
 * the frames cut out of each clip) and each fires this listener: a short
 * per-incident window collapses the burst into one broadcast; the frontend
 * debounces the rest.
 */
class RefreshIncidentOnEventMediaAvailable
{
    public const int BURST_WINDOW_SECONDS = 5;

    public function handle(EventMediaAvailable $event): void
    {
        $normalizedEvent = $event->normalizedEvent;

        TenantContext::for($normalizedEvent->team_id, function () use ($event, $normalizedEvent): void {
            $incident = Incident::query()
                ->where('related_event_id', $normalizedEvent->id)
                ->orderByDesc('id')
                ->first();

            $input = [
                'normalized_event_id' => $normalizedEvent->id,
                'event_media_context_id' => $event->media->id,
            ];

            if ($incident === null) {
                SystemLog::skipped('incidents.media.refresh_skipped', reason: 'no_incident', input: $input, debug: true);

                return;
            }

            $key = "incidents:media-refresh:{$incident->team_id}:{$incident->id}";

            if (! Cache::add($key, true, self::BURST_WINDOW_SECONDS)) {
                SystemLog::skipped('incidents.media.refresh_skipped', reason: 'burst_coalesced', input: [...$input, 'incident_id' => $incident->id], calc: ['window_seconds' => self::BURST_WINDOW_SECONDS], debug: true);

                return;
            }

            broadcast(IncidentUpdatedBroadcast::fromModel($incident));

            SystemLog::ok('incidents.media.refresh_broadcast', input: [...$input, 'incident_id' => $incident->id], calc: ['window_seconds' => self::BURST_WINDOW_SECONDS]);
        });
    }
}
