<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\Context\Events\EventContextBuilt;

class EvaluateOnEventContextBuilt
{
    public function handle(EventContextBuilt $event): void
    {
        EvaluateEventJob::dispatch($event->snapshot->normalized_event_id);
    }
}
