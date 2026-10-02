<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Jobs\ArchiveRawEventMediaJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Illuminate\Support\Arr;

class IngestSafetyEvent
{
    public const string USAGE_METER_CODE = 'ingested_events';

    /**
     * Circuito del lote: el poll reutiliza esta instancia para todos sus
     * eventos; tras el primer fallo de storage no se vuelve a esperar.
     */
    private bool $storageUnavailable = false;

    public function __construct(
        private StoreRawEvent $storeRawEvent,
        private QueueRawEventForProcessing $queueForProcessing,
        private RecordUsageEvent $recordUsageEvent,
        private ArchiveRawEventInlineMedia $archiveMedia,
    ) {}

    /**
     * Ingest one safety event from the provider's feed into the raw-event
     * funnel. The feed streams by `updatedAtTime`, so the same event id
     * reappears on state changes: the dedup key is `safety:{id}:{eventState}`
     * so transitions (e.g. → dismissed) pass through as updates while
     * same-state re-deliveries are dropped downstream.
     *
     * Inline media URLs are pre-signed and expire, so they are downloaded
     * immediately into raw-event attachments; the existing context pipeline
     * (`AttachImmediateEventMedia`) materializes them with no extra code.
     * Object storage is never on the critical path: if RustFS/S3 is down the
     * event is still queued and metered, and the media archive is deferred.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(TenantIntegration $integration, array $payload): ?RawEvent
    {
        $externalEventId = isset($payload['id']) ? (string) $payload['id'] : null;
        $eventState = (string) ($payload['eventState'] ?? 'unknown');

        $deduplicationKey = $externalEventId !== null
            ? "safety:{$externalEventId}:{$eventState}"
            : null;

        $isKnownDuplicate = $deduplicationKey !== null
            && $this->isKnownDuplicate($integration, $deduplicationKey);

        $rawEvent = $this->storeRawEvent->execute(
            payload: $payload,
            sourceType: EventSourceType::PollingFeed->value,
            teamId: $integration->team_id,
            providerId: $integration->provider_id,
            externalEventId: $externalEventId,
            deduplicationKey: $deduplicationKey,
            eventTypeRaw: Arr::get($payload, 'behaviorLabels.0.label') ?? 'SafetyEvent',
        );

        // El resto (descarga de media, encolado, uso) va en la traza del evento
        // recién guardado: el poll procesa muchos eventos en el mismo job.
        return PipelineTrace::within($rawEvent->trace_id, $rawEvent->team_id, function () use ($rawEvent, $integration, $payload, $isKnownDuplicate, $externalEventId, $eventState): RawEvent {
            // Duplicates are still stored (full audit trail) and still flow through
            // ProcessRawEventJob, which marks them and stops the pipeline — but
            // their media was already captured by the first delivery, so the
            // expiring URLs are not re-downloaded.
            if ($isKnownDuplicate) {
                SystemLog::skipped('ingestion.media.inline_skipped', reason: 'known_duplicate', input: ['raw_event_id' => $rawEvent->id, 'event_state' => $eventState]);
            } elseif ($this->isSwitchedOffUnit($integration, $payload)) {
                // Un safety event nunca es emergencia: la normalización lo
                // descartará por la unidad apagada. Su media no se baja a S3.
                SystemLog::skipped('ingestion.media.inline_skipped', reason: 'asset_not_monitored', input: ['raw_event_id' => $rawEvent->id, 'event_state' => $eventState]);
            } else {
                $this->archiveInlineMedia($rawEvent, $integration->team_id);
            }

            $this->queueForProcessing->execute($rawEvent);

            $this->recordUsage($integration, $externalEventId ?? (string) $rawEvent->id, $eventState, $rawEvent);

            return $rawEvent;
        }, $integration->provider?->code);
    }

    /**
     * La unidad del evento es de este tenant y no está vigilada. Una referencia
     * desconocida o de otro tenant no decide nada: la media se conserva y la
     * normalización resuelve como siempre.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isSwitchedOffUnit(TenantIntegration $integration, array $payload): bool
    {
        $externalId = Arr::get($payload, 'asset.id') ?? Arr::get($payload, 'vehicle.id') ?? Arr::get($payload, 'vehicleId');

        if (! is_scalar($externalId) || (string) $externalId === '') {
            return false;
        }

        $assetId = AssetExternalReference::query()
            ->where('provider_id', $integration->provider_id)
            ->where('external_id', (string) $externalId)
            ->value('asset_id');

        if ($assetId === null) {
            return false;
        }

        return Asset::query()
            ->whereKey($assetId)
            ->where('team_id', $integration->team_id)
            ->where('monitoring_state', '!=', AssetMonitoringState::Monitored)
            ->exists();
    }

    /**
     * A previous delivery with the same `safety:{id}:{eventState}` key already
     * stored this exact event+state, regardless of whether the async dedup
     * registry has processed it yet — checked against raw_events so media is
     * never re-downloaded even when the replay lands in the same poll batch.
     */
    private function isKnownDuplicate(TenantIntegration $integration, string $deduplicationKey): bool
    {
        return RawEvent::query()
            ->where('team_id', $integration->team_id)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();
    }

    /**
     * Archiva la media inline sin dejar que el storage de objetos frene la
     * ingesta: con RustFS/S3 caído el evento ya está en DB y sigue al
     * pipeline; el archivado se difiere a {@see ArchiveRawEventMediaJob}, que
     * relee las URLs del `payload_json` persistido. Tras el primer fallo de
     * storage, el resto del lote se difiere sin volver a esperarlo.
     */
    private function archiveInlineMedia(RawEvent $rawEvent, int $teamId): void
    {
        if ($this->storageUnavailable) {
            if ($this->archiveMedia->countMediaUrls($rawEvent) > 0) {
                $this->deferArchive($rawEvent, $teamId, 'storage_unavailable_earlier_in_batch');
            }

            return;
        }

        $result = $this->archiveMedia->execute($rawEvent);

        if ($result['storage_unavailable']) {
            $this->storageUnavailable = true;

            SystemLog::degraded('ingestion.media.storage_unavailable', reason: 'storage_unavailable', input: ['raw_event_id' => $rawEvent->id], error: $result['storage_error']);

            $this->deferArchive($rawEvent, $teamId, 'storage_failed');
        }

        SystemLog::ok('ingestion.media.inline_collected', input: ['raw_event_id' => $rawEvent->id], calc: ['urls_found' => $result['found'], 'downloaded' => $result['downloaded'], 'failed' => $result['failed']], result: ['archive_deferred' => $result['storage_unavailable']], debug: $result['found'] === 0);
    }

    private function deferArchive(RawEvent $rawEvent, int $teamId, string $trigger): void
    {
        ArchiveRawEventMediaJob::dispatch($rawEvent->id, $teamId)
            ->delay(now()->addSeconds(ArchiveRawEventMediaJob::OBJECT_STORAGE_FIRST_RETRY_SECONDS));

        SystemLog::degraded('ingestion.media.archive_deferred', reason: 'storage_unavailable', input: ['raw_event_id' => $rawEvent->id, 'trigger' => $trigger], calc: ['attempt' => 0, 'retry_in_seconds' => ArchiveRawEventMediaJob::OBJECT_STORAGE_FIRST_RETRY_SECONDS]);
    }

    private function recordUsage(TenantIntegration $integration, string $externalEventId, string $eventState, RawEvent $rawEvent): void
    {
        if (! UsageMeter::where('code', self::USAGE_METER_CODE)->exists()) {
            // Sin meter es un hueco de facturación.
            SystemLog::degraded('ingestion.usage.not_metered', reason: 'meter_missing', input: ['meter_code' => self::USAGE_METER_CODE, 'raw_event_id' => $rawEvent->id]);

            return;
        }

        $this->recordUsageEvent->execute(
            teamId: $integration->team_id,
            meterCode: self::USAGE_METER_CODE,
            quantity: 1,
            eventKey: "safety_event:{$integration->id}:{$externalEventId}:{$eventState}",
            metadata: [
                'raw_event_id' => $rawEvent->id,
                'tenant_integration_id' => $integration->id,
                'event_state' => $eventState,
            ],
            occurredAt: $rawEvent->occurred_at,
        );

        SystemLog::ok('ingestion.usage.recorded', input: ['meter_code' => self::USAGE_METER_CODE, 'raw_event_id' => $rawEvent->id, 'event_state' => $eventState]);
    }
}
