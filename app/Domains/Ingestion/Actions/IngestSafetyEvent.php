<?php

namespace App\Domains\Ingestion\Actions;

use App\Contracts\ObjectStorage;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Infrastructure\Storage\MediaDownloadException;
use App\Infrastructure\Storage\SecureMediaDownloader;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Illuminate\Support\Arr;

class IngestSafetyEvent
{
    public const string USAGE_METER_CODE = 'ingested_events';

    /**
     * Provider payload keys carrying inline (pre-signed, expiring) media URLs,
     * mapped to the local filename each download is stored under.
     */
    private const MEDIA_URL_KEYS = [
        'downloadForwardVideoUrl' => 'forward-video.mp4',
        'downloadInwardVideoUrl' => 'inward-video.mp4',
        'downloadTrackedInwardVideoUrl' => 'tracked-inward-video.mp4',
    ];

    public function __construct(
        private StoreRawEvent $storeRawEvent,
        private QueueRawEventForProcessing $queueForProcessing,
        private ObjectStorage $storage,
        private RecordUsageEvent $recordUsageEvent,
        private SecureMediaDownloader $downloader,
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
        return PipelineTrace::within($rawEvent->trace_id, $rawEvent->team_id, function () use ($rawEvent, $payload, $integration, $isKnownDuplicate, $externalEventId, $eventState): RawEvent {
            // Duplicates are still stored (full audit trail) and still flow through
            // ProcessRawEventJob, which marks them and stops the pipeline — but
            // their media was already captured by the first delivery, so the
            // expiring URLs are not re-downloaded.
            if ($isKnownDuplicate) {
                SystemLog::skipped('ingestion.media.inline_skipped', reason: 'known_duplicate', input: ['raw_event_id' => $rawEvent->id, 'event_state' => $eventState]);
            } else {
                $this->downloadInlineMedia($rawEvent, $payload);
            }

            $this->queueForProcessing->execute($rawEvent);

            $this->recordUsage($integration, $externalEventId ?? (string) $rawEvent->id, $eventState, $rawEvent);

            return $rawEvent;
        }, $integration->provider?->code);
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
     * @param  array<string, mixed>  $payload
     */
    private function downloadInlineMedia(RawEvent $rawEvent, array $payload): void
    {
        $found = 0;
        $downloaded = 0;
        $failed = 0;

        // Legacy safety-event shape: top-level download URLs.
        foreach (self::MEDIA_URL_KEYS as $key => $filename) {
            $url = Arr::get($payload, $key);

            if (! is_string($url) || $url === '') {
                continue;
            }

            $found++;
            $this->storeMediaDownload($rawEvent, $url, $filename, ['source_url_key' => $key]) ? $downloaded++ : $failed++;
        }

        // Stream v2 shape (`GET /safety-events/stream`): a `media` array with
        // one `{input, url, cameraRole}` item per camera stream. The URLs are
        // pre-signed and expire, so they must be captured at ingest time.
        foreach ((array) Arr::get($payload, 'media', []) as $index => $media) {
            $media = (array) $media;
            $url = $media['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $filename = sprintf('media-%d-%s.mp4', (int) $index, $this->mediaInputSlug($media['input'] ?? null));

            $found++;
            $this->storeMediaDownload($rawEvent, $url, $filename, array_filter([
                'source_url_key' => "media.{$index}.url",
                'input' => $media['input'] ?? null,
                'camera_role' => $media['cameraRole'] ?? null,
            ])) ? $downloaded++ : $failed++;
        }

        SystemLog::ok('ingestion.media.inline_collected', input: ['raw_event_id' => $rawEvent->id], calc: ['urls_found' => $found, 'downloaded' => $downloaded, 'failed' => $failed], debug: $found === 0);
    }

    /**
     * Download through the hardened downloader (https + host allowlist,
     * streamed to a temp file, size-capped) and store as a raw attachment.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function storeMediaDownload(RawEvent $rawEvent, string $url, string $filename, array $metadata): bool
    {
        try {
            $download = $this->downloader->download($url);
        } catch (MediaDownloadException $e) {
            SystemLog::degraded('ingestion.media.inline_download_failed', reason: 'download_failed', input: ['raw_event_id' => $rawEvent->id, 'url_key' => $metadata['source_url_key'] ?? null], error: $e);

            return false;
        }

        $storagePath = "teams/{$rawEvent->team_id}/raw-events/{$rawEvent->id}/{$filename}";
        $mimeType = $download->contentType ?: 'video/mp4';

        try {
            $stream = $download->stream();

            try {
                $this->storage->put($storagePath, $stream, [
                    'visibility' => 'private',
                    'ContentType' => $mimeType,
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            $download->cleanup();
        }

        RawEventAttachment::create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Clip,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'size_bytes' => $download->size,
            'metadata_json' => $metadata,
        ]);

        return true;
    }

    private function mediaInputSlug(?string $input): string
    {
        return match ($input) {
            'dashcamRoadFacing' => 'road-facing',
            'dashcamDriverFacing' => 'driver-facing',
            default => preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($input ?: 'unknown'))) ?: 'unknown',
        };
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
