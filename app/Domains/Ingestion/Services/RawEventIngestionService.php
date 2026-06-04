<?php

namespace App\Domains\Ingestion\Services;

use App\Contracts\RawEventIngestion;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Integrations\Models\IntegrationProvider;

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
        // $source is the provider code (e.g. "samsara"). Resolve it to a
        // provider id so normalization can map the event; without it the
        // event would always fall through to "unmapped".
        $providerId = IntegrationProvider::query()
            ->where('code', $source)
            ->value('id');

        $rawEvent = $this->storeRawEvent->execute(
            payload: $payload,
            sourceType: EventSourceType::Webhook->value,
            teamId: $teamId,
            providerId: $providerId,
            externalEventId: $payload['eventId'] ?? $payload['id'] ?? null,
        );

        $this->queueForProcessing->execute($rawEvent);
    }
}
