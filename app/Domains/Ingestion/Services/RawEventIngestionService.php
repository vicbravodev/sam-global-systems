<?php

namespace App\Domains\Ingestion\Services;

use App\Contracts\RawEventIngestion;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;

class RawEventIngestionService implements RawEventIngestion
{
    public function __construct(
        private StoreRawEvent $storeRawEvent,
        private QueueRawEventForProcessing $queueForProcessing,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(int $teamId, string $source, string $eventType, array $payload): void
    {
        $rawEvent = $this->storeRawEvent->execute(
            payload: $payload,
            sourceType: $source,
            teamId: $teamId,
            providerId: null,
            externalEventId: $payload['eventId'] ?? $payload['id'] ?? null,
        );

        $this->queueForProcessing->execute($rawEvent);
    }
}
