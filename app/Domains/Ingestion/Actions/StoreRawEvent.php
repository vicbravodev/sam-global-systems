<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Domains\Ingestion\Models\EventReceipt;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;

class StoreRawEvent
{
    /**
     * Persist a raw event exactly as received — no transformation.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $headers
     * @param  array{source_ip?: string, user_agent?: string, request_id?: string}  $transportMeta
     */
    public function execute(
        array $payload,
        string $sourceType,
        ?int $teamId,
        ?int $providerId,
        ?string $externalEventId = null,
        ?array $headers = null,
        array $transportMeta = [],
    ): RawEvent {
        $eventSource = $this->resolveEventSource($sourceType, $teamId, $providerId);

        $payloadJson = json_encode($payload);
        $checksum = hash('sha256', $payloadJson);
        $deduplicationKey = $externalEventId ?? $checksum;

        $rawEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'event_source_id' => $eventSource->id,
            'provider_id' => $providerId,
            'external_event_id' => $externalEventId,
            'event_type_raw' => $payload['eventType']
                ?? $payload['event_type']
                ?? $payload['behaviorLabels'][0]['label']
                ?? null,
            'payload_json' => $payload,
            'headers_json' => $headers,
            'received_at' => now(),
            'occurred_at' => $this->parseOccurredAt($payload),
            'deduplication_key' => $deduplicationKey,
            'status' => RawEventStatus::Received,
            'checksum' => $checksum,
        ]);

        EventReceipt::create([
            'raw_event_id' => $rawEvent->id,
            'received_via' => $sourceType,
            'request_id' => $transportMeta['request_id'] ?? null,
            'source_ip' => $transportMeta['source_ip'] ?? null,
            'user_agent' => $transportMeta['user_agent'] ?? null,
            'http_status_returned' => 200,
            'signature_valid' => null,
            'received_at' => now(),
        ]);

        RawEventReceived::dispatch($rawEvent);

        return $rawEvent;
    }

    private function resolveEventSource(string $sourceType, ?int $teamId, ?int $providerId): EventSource
    {
        return EventSource::withoutGlobalScopes()->firstOrCreate(
            [
                'team_id' => $teamId,
                'provider_id' => $providerId,
                'source_type' => $sourceType,
            ],
            [
                'source_name' => $sourceType,
                'status' => EventSourceStatus::Active,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseOccurredAt(array $payload): ?\DateTimeInterface
    {
        $timestamp = $payload['eventTime']
            ?? $payload['data']['happenedAtTime']
            ?? $payload['occurred_at']
            ?? null;

        if ($timestamp === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return null;
        }
    }
}
