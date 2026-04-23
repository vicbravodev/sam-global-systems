<?php

namespace App\Domains\Context\Listeners;

use App\Domains\Context\Jobs\EnrichContextJob;
use App\Domains\Normalization\Events\EventNormalized;

class EnrichContextOnEventNormalized
{
    public function handle(EventNormalized $event): void
    {
        EnrichContextJob::dispatch($event->normalizedEvent->id);
    }
}
