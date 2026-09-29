<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Domains\Ingestion\Models\EventReceipt;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Support\PipelineTrace;
use Illuminate\Support\Facades\Cache;

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
        ?string $deduplicationKey = null,
        ?string $eventTypeRaw = null,
    ): RawEvent {
        // Cada evento guardado es su propia traza: la del webhook que lo trajo
        // o una nueva (poll, monitores internos). En bucles, la traza nueva
        // sólo vive mientras se guarda: el contexto del llamador no cambia.
        return PipelineTrace::within(
            PipelineTrace::claimForNewEvent($teamId),
            $teamId,
            fn (): RawEvent => $this->store($payload, $sourceType, $teamId, $providerId, $externalEventId, $headers, $transportMeta, $deduplicationKey, $eventTypeRaw),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $headers
     * @param  array{source_ip?: string, user_agent?: string, request_id?: string}  $transportMeta
     */
    private function store(
        array $payload,
        string $sourceType,
        ?int $teamId,
        ?int $providerId,
        ?string $externalEventId,
        ?array $headers,
        array $transportMeta,
        ?string $deduplicationKey,
        ?string $eventTypeRaw,
    ): RawEvent {
        $eventSource = $this->resolveEventSource($sourceType, $teamId, $providerId);

        $payloadJson = json_encode($payload);
        $checksum = hash('sha256', $payloadJson);
        $deduplicationKey ??= $externalEventId ?? $checksum;

        $rawEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'trace_id' => PipelineTrace::id(),
            'event_source_id' => $eventSource->id,
            'provider_id' => $providerId,
            'external_event_id' => $externalEventId,
            'event_type_raw' => $eventTypeRaw
                ?? $payload['eventType']
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

        PipelineTrace::add([
            'raw_event_id' => $rawEvent->id,
            'external_event_id' => $externalEventId,
            'provider_id' => $providerId,
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

    /**
     * `event_sources` has no unique key on these columns, and dedup keys are
     * scoped by `event_source_id`: two concurrent first events of a tenant
     * creating two sources would silently split (and so disable) dedup. The
     * hot path is a plain lookup; only a miss takes a per-tenant lock.
     */
    private function resolveEventSource(string $sourceType, ?int $teamId, ?int $providerId): EventSource
    {
        $attributes = [
            'team_id' => $teamId,
            'provider_id' => $providerId,
            'source_type' => $sourceType,
        ];

        $find = fn (): ?EventSource => EventSource::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider_id', $providerId)
            ->where('source_type', $sourceType)
            ->orderBy('id')
            ->first();

        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        $lockKey = sprintf('ingestion:event-source:%s:%s:%s', $teamId ?? 'platform', $providerId ?? 'none', $sourceType);

        return Cache::lock($lockKey, 10)->block(5, fn (): EventSource => $find() ?? EventSource::withoutGlobalScopes()->create([
            ...$attributes,
            'source_name' => $sourceType,
            'status' => EventSourceStatus::Active,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseOccurredAt(array $payload): ?\DateTimeInterface
    {
        // Safety events stream (v2): `startMs` es el inicio real del evento
        // (ISO 8601 pese al nombre; o epoch ms) y `createdAtTime` cuando
        // Samsara lo registró. Sin ellos, el evento quedaba fechado a la hora
        // de recepción: tras una caída, todas las frenadas "ocurrían" al
        // recuperarse y la correlación alrededor del pánico mentía.
        $timestamp = $payload['eventTime']
            ?? $payload['data']['happenedAtTime']
            ?? $payload['startMs']
            ?? $payload['time']
            ?? $payload['createdAtTime']
            ?? $payload['occurred_at']
            ?? null;

        if ($timestamp === null) {
            return null;
        }

        if (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) {
            $value = (int) $timestamp;
            // Epoch en segundos (≤ 10 dígitos) o en milisegundos.
            $seconds = $value > 9_999_999_999 ? intdiv($value, 1000) : $value;

            return (new \DateTimeImmutable('@'.$seconds))->setTimezone(new \DateTimeZone('UTC'));
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return null;
        }
    }
}
