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

        $externalEventId = $payload['eventId'] ?? $payload['id'] ?? null;

        $rawEvent = $this->storeRawEvent->execute(
            payload: $payload,
            sourceType: EventSourceType::Webhook->value,
            teamId: $teamId,
            providerId: $providerId,
            externalEventId: $externalEventId,
            deduplicationKey: $this->buildDeduplicationKey($externalEventId, $payload),
        );

        $this->queueForProcessing->execute($rawEvent);
    }

    /**
     * Events that carry a resolution state (Samsara AlertIncident) must let
     * state transitions through dedup: the provider re-sends the same eventId
     * when the alert is resolved at the source. Keying on eventId alone would
     * silently drop the resolution update; keying on eventId + state keeps
     * same-state re-deliveries as duplicates.
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildDeduplicationKey(?string $externalEventId, array $payload): ?string
    {
        if ($externalEventId === null) {
            return null;
        }

        $isResolved = $payload['data']['isResolved'] ?? null;

        if (! is_bool($isResolved)) {
            return $externalEventId;
        }

        return $externalEventId.':'.($isResolved ? 'resolved' : 'open');
    }
}
