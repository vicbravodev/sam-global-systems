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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // Duplicates are still stored (full audit trail) and still flow through
        // ProcessRawEventJob, which marks them and stops the pipeline — but
        // their media was already captured by the first delivery, so the
        // expiring URLs are not re-downloaded.
        if (! $isKnownDuplicate) {
            $this->downloadInlineMedia($rawEvent, $payload);
        }

        $this->queueForProcessing->execute($rawEvent);

        $this->recordUsage($integration, $externalEventId ?? (string) $rawEvent->id, $eventState, $rawEvent);

        return $rawEvent;
    }

    /**
     * A previous delivery with the same `safety:{id}:{eventState}` key already
     * stored this exact event+state, regardless of whether the async dedup
     * registry has processed it yet — checked against raw_events so media is
     * never re-downloaded even when the replay lands in the same poll batch.
     */
    private function isKnownDuplicate(TenantIntegration $integration, string $deduplicationKey): bool
    {
        return RawEvent::withoutGlobalScopes()
            ->where('team_id', $integration->team_id)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function downloadInlineMedia(RawEvent $rawEvent, array $payload): void
    {
        // Legacy safety-event shape: top-level download URLs.
        foreach (self::MEDIA_URL_KEYS as $key => $filename) {
            $url = Arr::get($payload, $key);

            if (! is_string($url) || $url === '') {
                continue;
            }

            $this->storeMediaDownload($rawEvent, $url, $filename, ['source_url_key' => $key]);
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

            $this->storeMediaDownload($rawEvent, $url, $filename, array_filter([
                'source_url_key' => "media.{$index}.url",
                'input' => $media['input'] ?? null,
                'camera_role' => $media['cameraRole'] ?? null,
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function storeMediaDownload(RawEvent $rawEvent, string $url, string $filename, array $metadata): void
    {
        try {
            $response = Http::timeout((int) config('services.samsara.media_download_timeout', 30))->get($url);
        } catch (\Throwable $e) {
            Log::warning('Safety event inline media download failed', [
                'raw_event_id' => $rawEvent->id,
                'url_key' => $metadata['source_url_key'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || $response->body() === '') {
            return;
        }

        $storagePath = "teams/{$rawEvent->team_id}/raw-events/{$rawEvent->id}/{$filename}";
        $mimeType = $response->header('Content-Type') ?: 'video/mp4';

        $this->storage->put($storagePath, $response->body(), [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ]);

        RawEventAttachment::create([
            'raw_event_id' => $rawEvent->id,
            'attachment_type' => AttachmentType::Clip,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($response->body()),
            'metadata_json' => $metadata,
        ]);
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
    }
}
