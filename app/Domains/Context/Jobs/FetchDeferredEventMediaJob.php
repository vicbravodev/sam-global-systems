<?php

namespace App\Domains\Context\Jobs;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Contracts\ObjectStorage;
use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drive a deferred media request through the provider's retrieval cycle
 * (Roadmap B6-P3): place the retrieval on first run, then re-poll with a
 * delay until the provider reports the clips available, download them into
 * raw-event attachments and let {@see AttachImmediateEventMedia} materialize
 * the canonical `EventMediaContext`/`FileObject` rows. The request's own
 * `expires_at` (6h) bounds the polling chain: once past it the request is
 * marked expired and surfaced as a media failure.
 *
 * Roadmap V2-A1: the capture windows are tenant-configurable. Video clip
 * requests use `media.clip_window_seconds` per side around `occurred_at`;
 * `FetchSnapshot` requests place one still-image retrieval per timestamp,
 * evenly distributed across `occurred_at ± media.still_window_minutes`
 * (`media.still_count` stills), so the assessment pipeline can see what
 * happened around the event, not just at the instant.
 *
 * Every run also sweeps the provider's already-uploaded media for the event
 * window (`listUploadedMedia`) — panic-button/safety-event footage is
 * auto-uploaded by the dashcam, never announced via webhooks, and listing is
 * quota-free. The sweep repeats on every poll so late uploads still land, and
 * a request whose retrieval the provider rejects/fails closes as Completed
 * instead of Failed when that evidence already backs the event: a panic alert
 * must never end without media when the provider holds some.
 */
class FetchDeferredEventMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int POLL_DELAY_SECONDS = 60;

    public const string SETTING_CLIP_WINDOW = 'media.clip_window_seconds';

    public const string SETTING_STILL_WINDOW = 'media.still_window_minutes';

    public const string SETTING_STILL_COUNT = 'media.still_count';

    /** Device-side triggers whose auto-uploaded media count as event evidence. */
    public const array UPLOADED_TRIGGER_REASONS = ['panicButton', 'safetyEvent'];

    /**
     * Capture window around the event timestamp, per side (system default):
     * 10s per side → 20s clip. Samsara caps high-res retrieval duration and
     * longer clips burn the org's monthly media quota.
     */
    public const int DEFAULT_CLIP_WINDOW_SECONDS = 10;

    public const int DEFAULT_STILL_WINDOW_MINUTES = 30;

    public const int DEFAULT_STILL_COUNT = 6;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        public readonly int $eventMediaRequestId,
    ) {
        $this->onQueue('context');
    }

    public function handle(
        MediaRetrievalAdapter $mediaAdapter,
        ObjectStorage $storage,
        AttachImmediateEventMedia $attachImmediate,
        RefreshContextMediaSnapshot $refreshSnapshot,
        TenantConfigResolver $tenantConfig,
    ): void {
        $request = EventMediaRequest::withoutGlobalScopes()->find($this->eventMediaRequestId);

        if ($request === null || ! $request->status->isInFlight()) {
            return;
        }

        $event = NormalizedEvent::withoutGlobalScopes()->find($request->normalized_event_id);

        if ($event === null) {
            $this->markFailed($request, MediaRequestStatus::Failed, 'Normalized event no longer exists.');

            return;
        }

        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Expired, 'Media retrieval window expired before the provider delivered the media.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $resolved = $this->resolveIntegration($event);

        if ($resolved === null) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'No active integration with an external asset reference can serve this media request.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        [$integration, $externalAssetId] = $resolved;

        $this->sweepUploadedMedia($request, $event, $integration, $externalAssetId, $mediaAdapter, $storage, $attachImmediate, $refreshSnapshot, $tenantConfig);

        $metadata = $request->response_metadata_json ?? [];

        if ($request->request_type === MediaRequestType::FetchSnapshot) {
            $retrievals = $metadata['still_retrievals'] ?? null;

            if (! is_array($retrievals) || $retrievals === []) {
                $this->placeStillRetrievals($request, $event, $integration, $externalAssetId, $mediaAdapter, $tenantConfig, $refreshSnapshot);

                return;
            }

            $this->pollStillRetrievals($request, $event, $integration, $retrievals, $mediaAdapter, $storage, $attachImmediate, $refreshSnapshot);

            return;
        }

        $retrievalId = $metadata['retrieval_id'] ?? null;

        if (! is_string($retrievalId) || $retrievalId === '') {
            $this->placeRetrieval($request, $event, $integration, $externalAssetId, $mediaAdapter, $tenantConfig, $refreshSnapshot);

            return;
        }

        $this->pollRetrieval($request, $event, $integration, $retrievalId, $mediaAdapter, $storage, $attachImmediate, $refreshSnapshot);
    }

    private function placeRetrieval(
        EventMediaRequest $request,
        NormalizedEvent $event,
        TenantIntegration $integration,
        string $externalAssetId,
        MediaRetrievalAdapter $mediaAdapter,
        TenantConfigResolver $tenantConfig,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        $occurredAt = Carbon::instance($event->occurred_at ?? $request->requested_at ?? now());

        $windowSeconds = max(1, (int) $tenantConfig->resolve(
            (int) $event->team_id,
            self::SETTING_CLIP_WINDOW,
            self::DEFAULT_CLIP_WINDOW_SECONDS,
        ));

        $retrievalId = $mediaAdapter->requestMedia(
            $integration,
            $externalAssetId,
            $occurredAt->copy()->subSeconds($windowSeconds),
            $occurredAt->copy()->addSeconds($windowSeconds),
            $this->inputsFor($request->request_type),
        );

        if ($retrievalId === null) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider rejected the media retrieval request.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $metadata = $request->response_metadata_json ?? [];
        $metadata['retrieval_id'] = $retrievalId;
        $metadata['clip_window_seconds'] = $windowSeconds;

        $request->forceFill([
            'status' => MediaRequestStatus::Sent,
            'response_metadata_json' => $metadata,
        ])->save();

        $refreshSnapshot->execute($event->id);

        self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));
    }

    /**
     * Place one still-image retrieval per timestamp, spread evenly across the
     * tenant's still window around the event (Roadmap V2-A1). A partial
     * placement is fine — the request fails only when the provider rejects
     * every still.
     */
    private function placeStillRetrievals(
        EventMediaRequest $request,
        NormalizedEvent $event,
        TenantIntegration $integration,
        string $externalAssetId,
        MediaRetrievalAdapter $mediaAdapter,
        TenantConfigResolver $tenantConfig,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        $occurredAt = Carbon::instance($event->occurred_at ?? $request->requested_at ?? now());

        $count = max(1, (int) $tenantConfig->resolve(
            (int) $event->team_id,
            self::SETTING_STILL_COUNT,
            self::DEFAULT_STILL_COUNT,
        ));

        $windowSeconds = 60 * max(1, (int) $tenantConfig->resolve(
            (int) $event->team_id,
            self::SETTING_STILL_WINDOW,
            self::DEFAULT_STILL_WINDOW_MINUTES,
        ));

        $retrievals = [];

        foreach ($this->stillOffsets($count, $windowSeconds) as $index => $offset) {
            $instant = $occurredAt->copy()->addSeconds($offset);

            $retrievalId = $mediaAdapter->requestMedia(
                $integration,
                $externalAssetId,
                $instant,
                $instant,
                $this->inputsFor($request->request_type),
                'image',
            );

            if ($retrievalId !== null) {
                $retrievals[] = [
                    'retrieval_id' => $retrievalId,
                    'index' => $index,
                    'offset_seconds' => $offset,
                ];
            }
        }

        if ($retrievals === []) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider rejected every still-image retrieval request.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $metadata = $request->response_metadata_json ?? [];
        $metadata['still_retrievals'] = $retrievals;
        $metadata['still_window_seconds'] = $windowSeconds;

        $request->forceFill([
            'status' => MediaRequestStatus::Sent,
            'response_metadata_json' => $metadata,
        ])->save();

        $refreshSnapshot->execute($event->id);

        self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));
    }

    private function pollRetrieval(
        EventMediaRequest $request,
        NormalizedEvent $event,
        TenantIntegration $integration,
        string $retrievalId,
        MediaRetrievalAdapter $mediaAdapter,
        ObjectStorage $storage,
        AttachImmediateEventMedia $attachImmediate,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        $items = $mediaAdapter->checkMedia($integration, $retrievalId)['items'];

        $available = array_filter($items, fn (array $item) => $item['status'] === 'available' && is_string($item['url'] ?? null) && $item['url'] !== '');
        $pending = array_filter($items, fn (array $item) => $item['status'] === 'pending');

        $downloaded = 0;

        foreach ($available as $item) {
            $filename = 'deferred-'.$this->clipFilenameFor($item['input']);

            if ($this->downloadMedia($event, $item, $storage, $filename, AttachmentType::Clip, 'video/mp4')) {
                $downloaded++;
            }
        }

        if ($downloaded > 0) {
            $attachImmediate->execute($event);
        }

        // An empty item list means the provider could not be queried right now
        // (transient): keep polling until the request's expiry closes the loop.
        if ($pending !== [] || $items === []) {
            $request->forceFill(['status' => MediaRequestStatus::Processing])->save();
            $refreshSnapshot->execute($event->id);

            self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

            return;
        }

        if ($available === []) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider reported every requested clip as failed.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $request->forceFill([
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $refreshSnapshot->execute($event->id);
    }

    /**
     * Poll every still retrieval of the request and aggregate: the request
     * completes when no still remains pending and at least one image was
     * delivered; it fails only when the provider failed every still.
     *
     * @param  array<int, array{retrieval_id: string, index: int, offset_seconds: int}>  $retrievals
     */
    private function pollStillRetrievals(
        EventMediaRequest $request,
        NormalizedEvent $event,
        TenantIntegration $integration,
        array $retrievals,
        MediaRetrievalAdapter $mediaAdapter,
        ObjectStorage $storage,
        AttachImmediateEventMedia $attachImmediate,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        $anyPending = false;
        $anyTransient = false;
        $downloaded = 0;

        foreach ($retrievals as $retrieval) {
            $items = $mediaAdapter->checkMedia($integration, (string) $retrieval['retrieval_id'])['items'];

            if ($items === []) {
                $anyTransient = true;

                continue;
            }

            foreach ($items as $item) {
                if ($item['status'] === 'pending') {
                    $anyPending = true;

                    continue;
                }

                if ($item['status'] !== 'available' || ! is_string($item['url'] ?? null) || $item['url'] === '') {
                    continue;
                }

                $filename = sprintf(
                    'deferred-still-%d-%s',
                    (int) $retrieval['index'],
                    $this->stillFilenameFor($item['input']),
                );

                $item['offset_seconds'] = (int) $retrieval['offset_seconds'];

                if ($this->downloadMedia($event, $item, $storage, $filename, AttachmentType::Snapshot, 'image/jpeg')) {
                    $downloaded++;
                }
            }
        }

        $metadata = $request->response_metadata_json ?? [];
        $metadata['stills_downloaded'] = (int) ($metadata['stills_downloaded'] ?? 0) + $downloaded;

        if ($downloaded > 0) {
            $attachImmediate->execute($event);
        }

        if ($anyPending || $anyTransient) {
            $request->forceFill([
                'status' => MediaRequestStatus::Processing,
                'response_metadata_json' => $metadata,
            ])->save();

            $refreshSnapshot->execute($event->id);

            self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

            return;
        }

        if ((int) $metadata['stills_downloaded'] === 0) {
            $request->forceFill(['response_metadata_json' => $metadata])->save();
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider reported every requested still as failed.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $request->forceFill([
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
            'response_metadata_json' => $metadata,
        ])->save();

        $refreshSnapshot->execute($event->id);
    }

    /**
     * Download any media the device already auto-uploaded for the event window
     * (panic-button/safety-event triggers). Runs on every poll cycle so
     * uploads that land late are still captured — this is the monitorist
     * re-checking the camera after the event. Quota-free at the provider.
     */
    private function sweepUploadedMedia(
        EventMediaRequest $request,
        NormalizedEvent $event,
        TenantIntegration $integration,
        string $externalAssetId,
        MediaRetrievalAdapter $mediaAdapter,
        ObjectStorage $storage,
        AttachImmediateEventMedia $attachImmediate,
        RefreshContextMediaSnapshot $refreshSnapshot,
        TenantConfigResolver $tenantConfig,
    ): void {
        $occurredAt = Carbon::instance($event->occurred_at ?? $request->requested_at ?? now());

        $windowSeconds = 60 * max(1, (int) $tenantConfig->resolve(
            (int) $event->team_id,
            self::SETTING_STILL_WINDOW,
            self::DEFAULT_STILL_WINDOW_MINUTES,
        ));

        $items = $mediaAdapter->listUploadedMedia(
            $integration,
            $externalAssetId,
            $occurredAt->copy()->subSeconds($windowSeconds),
            $occurredAt->copy()->addSeconds($windowSeconds),
            self::UPLOADED_TRIGGER_REASONS,
        )['items'];

        $downloaded = 0;

        foreach ($items as $item) {
            if ($item['status'] !== 'available' || ! is_string($item['url'] ?? null) || $item['url'] === '') {
                continue;
            }

            $isVideo = str_starts_with((string) ($item['media_type'] ?? ''), 'video');

            $stored = $this->downloadMedia(
                $event,
                $item,
                $storage,
                $this->uploadedFilenameFor($item),
                $isVideo ? AttachmentType::Clip : AttachmentType::Snapshot,
                $isVideo ? 'video/mp4' : 'image/jpeg',
                'uploaded_media',
            );

            if ($stored) {
                $downloaded++;
            }
        }

        if ($downloaded === 0) {
            return;
        }

        $attachImmediate->execute($event);

        $metadata = $request->response_metadata_json ?? [];
        $metadata['uploaded_media_downloaded'] = (int) ($metadata['uploaded_media_downloaded'] ?? 0) + $downloaded;

        $request->forceFill(['response_metadata_json' => $metadata])->save();

        $refreshSnapshot->execute($event->id);
    }

    /**
     * Close a request whose retrieval path delivered nothing: when the event
     * already holds auto-uploaded evidence the request completes (the alert IS
     * backed by media), otherwise it fails/expires as before.
     */
    private function closeWithoutNewMedia(
        EventMediaRequest $request,
        NormalizedEvent $event,
        MediaRequestStatus $status,
        string $reason,
    ): void {
        $hasUploadedEvidence = RawEventAttachment::query()
            ->where('raw_event_id', $event->raw_event_id)
            ->where('metadata_json->source', 'uploaded_media')
            ->exists();

        if (! $hasUploadedEvidence) {
            $this->markFailed($request, $status, $reason);

            return;
        }

        $metadata = $request->response_metadata_json ?? [];
        $metadata['completed_via'] = 'uploaded_media';
        $metadata['retrieval_close_reason'] = $reason;

        $request->forceFill([
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
            'response_metadata_json' => $metadata,
        ])->save();
    }

    /**
     * Deterministic filename per uploaded item (trigger + capture instant +
     * input) so every poll's sweep dedupes against storage instead of
     * re-downloading.
     *
     * @param  array{input: string|null, media_type?: string|null, trigger_reason?: string|null, start_time?: string|null}  $item
     */
    private function uploadedFilenameFor(array $item): string
    {
        $startTime = $item['start_time'] ?? null;

        $stamp = is_string($startTime) && $startTime !== ''
            ? Carbon::parse($startTime)->utc()->format('Ymd-His')
            : 'unknown';

        $input = match ($item['input']) {
            'dashcamRoadFacing' => 'road-facing',
            'dashcamDriverFacing' => 'driver-facing',
            default => 'input-'.substr(md5((string) $item['input']), 0, 8),
        };

        $trigger = preg_replace('/[^a-zA-Z0-9]+/', '', (string) ($item['trigger_reason'] ?? 'unknown')) ?: 'unknown';

        $extension = str_starts_with((string) ($item['media_type'] ?? ''), 'video') ? 'mp4' : 'jpg';

        return sprintf('uploaded-%s-%s-%s.%s', $trigger, $stamp, $input, $extension);
    }

    /**
     * Even spread of capture instants across `±$windowSeconds` around the
     * event: a single still lands on the event itself, several cover the
     * whole window from earliest to latest.
     *
     * @return array<int, int>
     */
    private function stillOffsets(int $count, int $windowSeconds): array
    {
        if ($count === 1) {
            return [0];
        }

        $offsets = [];
        $step = (2 * $windowSeconds) / ($count - 1);

        for ($i = 0; $i < $count; $i++) {
            $offsets[] = (int) round(-$windowSeconds + ($i * $step));
        }

        return $offsets;
    }

    /**
     * @param  array{input: string|null, status: string, url: string|null, offset_seconds?: int, trigger_reason?: string|null}  $item
     */
    private function downloadMedia(
        NormalizedEvent $event,
        array $item,
        ObjectStorage $storage,
        string $filename,
        AttachmentType $type,
        string $defaultMimeType,
        string $source = 'deferred_retrieval',
    ): bool {
        $storagePath = "teams/{$event->team_id}/raw-events/{$event->raw_event_id}/{$filename}";

        // Re-polls list already-downloaded media as available again: skip the
        // HTTP fetch when the binary is already on storage, but make sure the
        // attachment row exists (a crash may have landed between put and row).
        if ($storage->exists($storagePath)) {
            RawEventAttachment::firstOrCreate(
                [
                    'raw_event_id' => $event->raw_event_id,
                    'storage_path' => $storagePath,
                ],
                [
                    'attachment_type' => $type,
                    'mime_type' => $storage->mimeType($storagePath) ?? $defaultMimeType,
                    'size_bytes' => $storage->size($storagePath) ?? 0,
                    'metadata_json' => ['source' => $source, 'input' => $item['input']],
                ],
            );

            return false;
        }

        try {
            $response = Http::timeout((int) config('services.samsara.media_download_timeout', 30))->get((string) $item['url']);
        } catch (\Throwable $e) {
            Log::warning('Deferred media download failed', [
                'normalized_event_id' => $event->id,
                'input' => $item['input'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful() || $response->body() === '') {
            return false;
        }

        $mimeType = $response->header('Content-Type') ?: $defaultMimeType;

        $storage->put($storagePath, $response->body(), [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ]);

        $metadata = ['source' => $source, 'input' => $item['input']];

        if (array_key_exists('offset_seconds', $item)) {
            $metadata['offset_seconds'] = (int) $item['offset_seconds'];
        }

        if (! empty($item['trigger_reason'])) {
            $metadata['trigger_reason'] = (string) $item['trigger_reason'];
        }

        RawEventAttachment::firstOrCreate(
            [
                'raw_event_id' => $event->raw_event_id,
                'storage_path' => $storagePath,
            ],
            [
                'attachment_type' => $type,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($response->body()),
                'metadata_json' => $metadata,
            ],
        );

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function inputsFor(MediaRequestType $type): array
    {
        return match ($type) {
            MediaRequestType::FetchRoadCamera => ['dashcamRoadFacing'],
            MediaRequestType::FetchDriverCamera => ['dashcamDriverFacing'],
            default => ['dashcamRoadFacing', 'dashcamDriverFacing'],
        };
    }

    private function clipFilenameFor(?string $input): string
    {
        return match ($input) {
            'dashcamRoadFacing' => 'road-facing.mp4',
            'dashcamDriverFacing' => 'driver-facing.mp4',
            default => 'clip-'.substr(md5((string) $input), 0, 8).'.mp4',
        };
    }

    private function stillFilenameFor(?string $input): string
    {
        return match ($input) {
            'dashcamRoadFacing' => 'road-facing.jpg',
            'dashcamDriverFacing' => 'driver-facing.jpg',
            default => 'still-'.substr(md5((string) $input), 0, 8).'.jpg',
        };
    }

    /**
     * @return array{0: TenantIntegration, 1: string}|null
     */
    private function resolveIntegration(NormalizedEvent $event): ?array
    {
        if ($event->team_id === null || $event->asset_id === null) {
            return null;
        }

        $references = AssetExternalReference::query()
            ->where('asset_id', $event->asset_id)
            ->whereNotNull('external_id')
            ->get();

        foreach ($references as $reference) {
            $integration = TenantIntegration::withoutGlobalScopes()
                ->where('team_id', $event->team_id)
                ->where('provider_id', $reference->provider_id)
                ->where('status', TenantIntegrationStatus::Active)
                ->first();

            if ($integration !== null) {
                return [$integration, (string) $reference->external_id];
            }
        }

        return null;
    }

    private function markFailed(EventMediaRequest $request, MediaRequestStatus $status, string $reason): void
    {
        $request->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();

        EventMediaFailed::dispatch($request, $reason);

        Log::warning('FetchDeferredEventMediaJob closed request without media', [
            'event_media_request_id' => $request->id,
            'status' => $status->value,
            'reason' => $reason,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $request = EventMediaRequest::withoutGlobalScopes()->find($this->eventMediaRequestId);

        if ($request !== null && $request->status->isInFlight()) {
            $request->forceFill([
                'status' => MediaRequestStatus::Failed,
                'completed_at' => now(),
            ])->save();

            EventMediaFailed::dispatch($request, $exception->getMessage());
        }

        Log::warning('FetchDeferredEventMediaJob failed', [
            'event_media_request_id' => $this->eventMediaRequestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
