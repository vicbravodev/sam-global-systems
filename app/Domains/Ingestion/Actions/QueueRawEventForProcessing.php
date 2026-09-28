<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Support\PipelineTrace;

class QueueRawEventForProcessing
{
    /**
     * Mark the raw event as pending and dispatch it for async processing.
     */
    public function execute(RawEvent $rawEvent): void
    {
        $rawEvent->markAsPendingProcessing();

        // Closure de bloque: PendingDispatch encola al destruirse y debe
        // hacerlo DENTRO de la traza del evento, no después de restaurarla.
        PipelineTrace::within($rawEvent->trace_id, $rawEvent->team_id, function () use ($rawEvent): void {
            ProcessRawEventJob::dispatch($rawEvent->id);
        });
    }
}
