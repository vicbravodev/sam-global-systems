<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Normalization\Events\EventNormalized;

class ApplyExternalResolutionOnEventNormalized
{
    public function handle(EventNormalized $event): void
    {
        $normalizedEvent = $event->normalizedEvent;

        if (($normalizedEvent->payload_normalized_json['is_resolved'] ?? null) !== true) {
            return;
        }

        ApplyExternalResolutionJob::dispatch((int) $normalizedEvent->id)->afterCommit();
    }
}
