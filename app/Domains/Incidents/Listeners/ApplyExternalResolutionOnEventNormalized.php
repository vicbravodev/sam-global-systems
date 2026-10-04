<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\NormalizedEventUpdated;

/**
 * Un evento que llega resuelto en origen (un `AlertIncident` con `isResolved`)
 * o un safety event descartado en Samsara ({@see NormalizedEventUpdated}).
 */
class ApplyExternalResolutionOnEventNormalized
{
    public function handle(EventNormalized|NormalizedEventUpdated $event): void
    {
        $normalizedEvent = $event->normalizedEvent;

        if (($normalizedEvent->payload_normalized_json['is_resolved'] ?? null) !== true) {
            return;
        }

        ApplyExternalResolutionJob::dispatch($normalizedEvent->id)->afterCommit();
    }
}
