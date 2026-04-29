<?php

namespace App\Domains\Context\Listeners;

use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Jobs\ExtractEventMediaJob;

class ExtractMediaOnContextBuilt
{
    public function handle(EventContextBuilt $event): void
    {
        ExtractEventMediaJob::dispatch($event->snapshot->normalized_event_id);
    }
}
