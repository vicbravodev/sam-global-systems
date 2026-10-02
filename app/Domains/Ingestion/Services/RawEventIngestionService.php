<?php

namespace App\Domains\Ingestion\Services;

use App\Contracts\RawEventIngestion;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\ResolveAlertIncidentIdentity;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Support\LoggableCode;
use App\Support\SystemLog;

class RawEventIngestionService implements RawEventIngestion
{
    public function __construct(
        private StoreRawEvent $storeRawEvent,
        private QueueRawEventForProcessing $queueForProcessing,
        private ResolveAlertIncidentIdentity $alertIncidentIdentity,
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

        if ($providerId === null) {
            // Sin proveedor el evento terminará `unmapped`.
            // Ambos pueden venir de una petición sin autenticar: sólo si parecen códigos.
            $loggableEventType = LoggableCode::guard($eventType);

            SystemLog::degraded('ingestion.provider.unresolved', reason: 'unknown_provider_code', input: [
                'provider_code' => LoggableCode::guard($source),
                'event_type' => $loggableEventType,
                'event_type_valid' => $loggableEventType !== null,
            ]);
        }

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
     * Samsara AlertIncident (p. ej. el botón de pánico) se deduplica por la
     * identidad del INCIDENTE, no por `eventId` (que es de la entrega): así
     * un reenvío con otro `eventId` y el poll de respaldo
     * (PollAlertIncidentsJob) caen en la misma clave. Ver
     * {@see ResolveAlertIncidentIdentity}.
     *
     * Events that carry a resolution state must let state transitions through
     * dedup: the provider re-sends the same incident when the alert is
     * resolved at the source. Keying on the identity alone would silently
     * drop the resolution update; keying on identity + state keeps same-state
     * re-deliveries as duplicates. Without identity (payload legacy) the key
     * falls back to eventId + state.
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildDeduplicationKey(?string $externalEventId, array $payload): ?string
    {
        $data = $payload['data'] ?? null;

        if (($payload['eventType'] ?? null) === 'AlertIncident' && is_array($data)) {
            $identityKey = $this->alertIncidentIdentity->key($data);

            if ($identityKey !== null) {
                return $identityKey;
            }
        }

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
