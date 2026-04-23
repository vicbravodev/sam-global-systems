<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\Normalization\Events\EventNormalized;

class EvaluateEventOnEventNormalized
{
    public function handle(EventNormalized $event): void
    {
        EvaluateEventJob::dispatch($event->normalizedEvent->id);
    }
}
