<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;

class QueueRawEventForProcessing
{
    /**
     * Mark the raw event as pending and dispatch it for async processing.
     */
    public function execute(RawEvent $rawEvent): void
    {
        $rawEvent->markAsPendingProcessing();

        ProcessRawEventJob::dispatch($rawEvent->id);
    }
}
