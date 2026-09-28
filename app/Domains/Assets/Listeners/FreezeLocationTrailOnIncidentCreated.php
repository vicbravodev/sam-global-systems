<?php

namespace App\Domains\Assets\Listeners;

use App\Domains\Assets\Jobs\FreezeIncidentLocationTrailJob;
use App\Domains\Incidents\Events\IncidentCreated;

/**
 * Schedule the freeze of an incident's GPS trail once the window after its
 * opening has been recorded. See {@see FreezeIncidentLocationTrailJob}.
 */
class FreezeLocationTrailOnIncidentCreated
{
    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        if ($incident->asset_id === null) {
            return;
        }

        FreezeIncidentLocationTrailJob::dispatch($incident->id, $incident->team_id)
            ->delay(now()->addMinutes((int) config('telematics.incident_trail_minutes', 30)));
    }
}
