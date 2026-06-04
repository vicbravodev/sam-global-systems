<?php

namespace App\Domains\Normalization\Listeners;

use App\Domains\Ingestion\Events\RawEventProcessed;
use App\Domains\Normalization\Jobs\NormalizeEventJob;

class NormalizeOnRawEventProcessed
{
    /**
     * Bridge the Ingestion pipeline into Normalization: once a raw event has
     * been ingested and de-duplicated, queue it for normalization so the rest
     * of the pipeline (Context → AI → Decisions → Incidents) can run.
     */
    public function handle(RawEventProcessed $event): void
    {
        NormalizeEventJob::dispatch($event->rawEvent->id);
    }
}
