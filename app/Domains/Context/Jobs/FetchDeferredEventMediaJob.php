<?php

namespace App\Domains\Context\Jobs;

use App\Concerns\DefersOnObjectStorageOutage;
use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Contracts\ObjectStorage;
use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaDownloadOutcome;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Infrastructure\Storage\MediaDownloadException;
use App\Infrastructure\Storage\SecureMediaDownloader;
use App\Support\JobFailureReporter;
use App\Support\ObjectStorageFailure;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

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
 *
 * Retrievals are skipped entirely in two cases (the sweep still runs):
 * events older than `media.retrieval_max_age_hours` (the dashcam SD card
 * overwrites itself within days, so the provider would reject every call),
 * and assets whose provider record reports no paired camera (the flag can be
 * stale, so the request stays alive as a sweep-only poll until uploaded
 * media lands or it expires).
 */
class FetchDeferredEventMediaJob implements ShouldQueue
{
    use DefersOnObjectStorageOutage, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int POLL_DELAY_SECONDS = 60;

    /** Sweep-only polls have no retrieval to babysit: re-check uploads slower. */
    public const int SWEEP_POLL_DELAY_SECONDS = 300;

    public const string SETTING_CLIP_WINDOW = 'media.clip_window_seconds';

    public const string SETTING_RETRIEVAL_MAX_AGE = 'media.retrieval_max_age_hours';

    public const string SETTING_STILL_WINDOW = 'media.still_window_minutes';

    public const string SETTING_STILL_COUNT = 'media.still_count';

    /**
     * Device-side triggers whose auto-uploaded media count as event evidence:
     * the panic/safety clip itself, or footage someone already pulled for
     * that instant (dashboard retrieval, API).
     */
    public const array UPLOADED_TRIGGER_REASONS = ['panicButton', 'safetyEvent', 'videoRetrieval', 'api'];

    /**
     * Stills the dashcam takes on its own schedule (every ~2 min per camera
     * while driving, plus trip start/end). They are not footage OF the event,
     * but for detections Samsara never uploads a clip for (passenger,
     * obstructed camera) or events no device trigger covers (after-hours
     * movement) they are the only picture of the cab and the road at that
     * moment. Only the nearest one before and after the event per camera is
     * kept ({@see CONTEXT_MATCH_SECONDS}).
     */
    public const array CONTEXT_TRIGGER_REASONS = ['periodicStill', 'tripStartStill', 'tripEndStill'];

    /** Farthest a context still may be from the event: one periodic cycle and a half. */
    public const int CONTEXT_MATCH_SECONDS = 180;

    /**
     * A sweep-only request backed only by context stills keeps polling this
     * long for the event's own clip (a panic/safety upload lands minutes after
     * the press) before it settles for the stills.
     */
    public const int CONTEXT_SETTLE_SECONDS = 900;

    /**
     * An emergency whose dashcam uploaded nothing after this long gets one
     * paid clip retrieval of both cameras — a panic must never be left without
     * footage while the device may still hold it.
     */
    public const int EMERGENCY_FALLBACK_AFTER_SECONDS = 300;

    /**
     * An uploaded item belongs to the event only when its capture instant is
     * this close to `occurred_at`. The dashcam starts the panic clip ~10 s
     * before the press, while the listing window spans half an hour: without
     * this match two presses of the same unit minutes apart (or a harsh-brake
     * upload before the panic) would all land on every event of the window.
     */
    public const int UPLOADED_MATCH_SECONDS = 60;

    /**
     * Capture window around the event timestamp, per side (system default):
     * 10s per side → 20s clip. Samsara caps high-res retrieval duration and
     * longer clips burn the org's monthly media quota.
     */
    public const int DEFAULT_CLIP_WINDOW_SECONDS = 10;

    /** Samsara caps high-res video retrievals at 1 minute total → 30s per side. */
    public const int MAX_CLIP_WINDOW_SECONDS = 30;

    public const int DEFAULT_STILL_WINDOW_MINUTES = 30;

    public const int DEFAULT_STILL_COUNT = 6;

    /** Past this event age the SD footage is gone and every retrieval 400s. */
    public const int DEFAULT_RETRIEVAL_MAX_AGE_HOURS = 72;

    /**
     * Con `retryUntil()` (de {@see DefersOnObjectStorageOutage}) Laravel
     * ignora `$tries`: los fallos que no son de storage siguen acotados aquí.
     */
    public int $maxExceptions = 5;

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
            SystemLog::skipped(
                'media.deferred.skipped',
                reason: $request === null ? 'request_missing' : 'not_in_flight',
                input: ['event_media_request_id' => $this->eventMediaRequestId, 'status' => $request?->status->value],
            );

            return;
        }

        // El job entra por su propio id: resuelve el tenant de la petición y
        // procesa dentro de él, para que la búsqueda de integración, activo y
        // media quede scopeada. Ver §2.1.
        try {
            TenantContext::for($request->team_id, fn () => $this->process(
                $request,
                $mediaAdapter,
                $storage,
                $attachImmediate,
                $refreshSnapshot,
                $tenantConfig,
            ));
        } catch (Throwable $e) {
            if (! ObjectStorageFailure::matches($e)) {
                throw $e;
            }

            // RustFS/S3 caído: la petición sigue en vuelo y el mismo ciclo se
            // reintenta cuando vuelva; el `expires_at` de la petición acota
            // la espera igual que acota el polling normal.
            $this->deferForObjectStorageOutage('media.deferred.storage_unavailable', ['event_media_request_id' => $request->id, 'normalized_event_id' => $request->normalized_event_id], $e);
        }
    }

    private function process(
        EventMediaRequest $request,
        MediaRetrievalAdapter $mediaAdapter,
        ObjectStorage $storage,
        AttachImmediateEventMedia $attachImmediate,
        RefreshContextMediaSnapshot $refreshSnapshot,
        TenantConfigResolver $tenantConfig,
    ): void {
        $event = NormalizedEvent::query()->find($request->normalized_event_id);

        if ($event === null) {
            $this->markFailed($request, MediaRequestStatus::Failed, 'Normalized event no longer exists.', 'normalized_event_missing');

            return;
        }

        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Expired, 'Media retrieval window expired before the provider delivered the media.', 'retrieval_window_expired');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $resolved = $this->resolveIntegration($event);

        if ($resolved === null) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'No active integration with an external asset reference can serve this media request.', 'no_active_integration');
            $refreshSnapshot->execute($event->id);

            return;
        }

        [$integration, $externalAssetId] = $resolved;

        $this->sweepUploadedMedia($request, $event, $integration, $externalAssetId, $mediaAdapter, $storage, $attachImmediate, $refreshSnapshot, $tenantConfig);

        $metadata = $request->response_metadata_json ?? [];

        // Sweep-only requests (auto-pull for incident-worthy events) keep
        // listing the quota-free uploads until they land or the request
        // expires. The one exception is an emergency the dashcam uploaded
        // nothing for: it escalates to a single paid clip retrieval, which is
        // then polled like any other.
        if ($request->sweep_only && ! isset($metadata['retrieval_id'])) {
            $this->continueSweepOnly(
                $request,
                $event,
                $refreshSnapshot,
                'Request fulfilled by the quota-free uploaded-media sweep.',
                'fulfilled_by_sweep',
                fn (): bool => $this->placeEmergencyFallback($request, $event, $integration, $externalAssetId, $mediaAdapter, $tenantConfig, $refreshSnapshot),
            );

            return;
        }

        if (! isset($metadata['retrieval_id']) && ! isset($metadata['still_retrievals'])) {
            $retention = $this->footageRetention($request, $event, $tenantConfig);

            if ($retention['expired']) {
                $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, sprintf(
                    'Event is older than the device footage retention window (%dh); only already-uploaded media was swept.',
                    $retention['max_age_hours'],
                ), 'older_than_footage_retention', [
                    'event_age_hours' => $retention['event_age_hours'],
                    'max_age_hours' => $retention['max_age_hours'],
                ]);
                $refreshSnapshot->execute($event->id);

                return;
            }

            if ($this->assetReportsNoCamera($event)) {
                $this->continueSweepOnly(
                    $request,
                    $event,
                    $refreshSnapshot,
                    'Asset reports no paired camera; request fulfilled by the uploaded-media sweep.',
                    'no_camera_fulfilled_by_sweep',
                );

                return;
            }
        }

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

        $windowSeconds = min(self::MAX_CLIP_WINDOW_SECONDS, max(1, (int) $tenantConfig->resolve(
            $event->team_id,
            self::SETTING_CLIP_WINDOW,
            self::DEFAULT_CLIP_WINDOW_SECONDS,
        )));

        $retrievalId = $mediaAdapter->requestMedia(
            $integration,
            $externalAssetId,
            $occurredAt->copy()->subSeconds($windowSeconds),
            $occurredAt->copy()->addSeconds($windowSeconds),
            $this->inputsFor($request->request_type),
        );

        if ($retrievalId === null) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider rejected the media retrieval request.', 'provider_rejected_retrieval');
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

        SystemLog::ok(
            'media.deferred.retrieval_placed',
            input: $this->logInput($request),
            calc: [
                'media_type' => 'video',
                'inputs' => $this->inputsFor($request->request_type),
                'next_poll_seconds' => self::POLL_DELAY_SECONDS,
            ],
        );

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
            $event->team_id,
            self::SETTING_STILL_COUNT,
            self::DEFAULT_STILL_COUNT,
        ));

        $windowSeconds = 60 * max(1, (int) $tenantConfig->resolve(
            $event->team_id,
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
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider rejected every still-image retrieval request.', 'provider_rejected_all_stills');
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

        SystemLog::ok(
            'media.deferred.stills_placed',
            input: $this->logInput($request),
            calc: [
                'stills_requested' => count($retrievals),
                'stills_rejected' => $count - count($retrievals),
                'next_poll_seconds' => self::POLL_DELAY_SECONDS,
            ],
        );

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
        $alreadyStored = 0;
        $failedDownloads = 0;

        foreach ($available as $item) {
            $filename = 'deferred-'.$this->clipFilenameFor($item['input']);

            $outcome = $this->downloadMedia($event, $item, $storage, $filename, AttachmentType::Clip, 'video/mp4');

            if ($outcome === MediaDownloadOutcome::Stored) {
                $downloaded++;
            } elseif ($outcome === MediaDownloadOutcome::AlreadyExists) {
                $alreadyStored++;
            } elseif ($outcome === MediaDownloadOutcome::Failed) {
                $failedDownloads++;
            }
        }

        if ($downloaded > 0) {
            $attachImmediate->execute($event);
        }

        // An empty item list means the provider could not be queried right now
        // (transient), and a failed download means the clip is still waiting
        // at the provider: keep polling until the request's expiry closes the
        // loop instead of completing a request that stored nothing.
        if ($pending !== [] || $items === [] || $failedDownloads > 0) {
            $request->forceFill(['status' => MediaRequestStatus::Processing])->save();
            $refreshSnapshot->execute($event->id);

            SystemLog::ok(
                'media.deferred.polling',
                input: $this->logInput($request),
                calc: [
                    'pending' => count($pending),
                    'available' => count($available),
                    'failed_downloads' => $failedDownloads,
                    'items' => count($items),
                    'next_poll_seconds' => self::POLL_DELAY_SECONDS,
                ],
                result: ['requeue_reason' => $pending !== [] ? 'pending_at_provider' : ($items === [] ? 'provider_unreachable' : 'download_failed')],
            );

            self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

            return;
        }

        if ($available === []) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider reported every requested clip as failed.', 'all_clips_failed');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $request->forceFill([
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $refreshSnapshot->execute($event->id);

        SystemLog::ok(
            'media.deferred.completed',
            input: $this->logInput($request),
            result: ['available' => count($available), 'downloaded' => $downloaded, 'already_stored' => $alreadyStored],
        );
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
        $alreadyStored = 0;
        $itemsSeen = 0;
        $pendingCount = 0;
        $availableCount = 0;
        $failedDownloads = 0;

        foreach ($retrievals as $retrieval) {
            $items = $mediaAdapter->checkMedia($integration, $retrieval['retrieval_id'])['items'];
            $itemsSeen += count($items);

            if ($items === []) {
                $anyTransient = true;

                continue;
            }

            foreach ($items as $item) {
                if ($item['status'] === 'pending') {
                    $anyPending = true;
                    $pendingCount++;

                    continue;
                }

                if ($item['status'] !== 'available' || ! is_string($item['url'] ?? null) || $item['url'] === '') {
                    continue;
                }

                $availableCount++;

                $filename = sprintf(
                    'deferred-still-%d-%s',
                    $retrieval['index'],
                    $this->stillFilenameFor($item['input']),
                );

                $item['offset_seconds'] = $retrieval['offset_seconds'];

                $outcome = $this->downloadMedia($event, $item, $storage, $filename, AttachmentType::Snapshot, 'image/jpeg');

                if ($outcome === MediaDownloadOutcome::Stored) {
                    $downloaded++;
                } elseif ($outcome === MediaDownloadOutcome::AlreadyExists) {
                    $alreadyStored++;
                } elseif ($outcome === MediaDownloadOutcome::Failed) {
                    $anyTransient = true;
                    $failedDownloads++;
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

            SystemLog::ok(
                'media.deferred.polling',
                input: $this->logInput($request),
                calc: [
                    'pending' => $pendingCount,
                    'available' => $availableCount,
                    'failed_downloads' => $failedDownloads,
                    'items' => $itemsSeen,
                    'next_poll_seconds' => self::POLL_DELAY_SECONDS,
                ],
                result: ['requeue_reason' => $anyPending ? 'pending_at_provider' : ($failedDownloads > 0 ? 'download_failed' : 'provider_unreachable')],
            );

            self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

            return;
        }

        if ($metadata['stills_downloaded'] === 0) {
            $request->forceFill(['response_metadata_json' => $metadata])->save();
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Provider reported every requested still as failed.', 'all_stills_failed');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $request->forceFill([
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
            'response_metadata_json' => $metadata,
        ])->save();

        $refreshSnapshot->execute($event->id);

        SystemLog::ok(
            'media.deferred.completed',
            input: $this->logInput($request),
            result: ['available' => $availableCount, 'downloaded' => $downloaded, 'already_stored' => $alreadyStored, 'stills_downloaded_total' => $metadata['stills_downloaded']],
        );
    }

    /**
     * Download any media the device already uploaded for the event window.
     * Runs on every poll cycle so uploads that land late are still captured —
     * this is the monitorist re-checking the camera after the event.
     * Quota-free at the provider.
     *
     * Two kinds of uploads are kept: event evidence (panic/safety clips and
     * prior retrievals, matched to the event instant) and context stills (the
     * dashcam's own periodic/trip photos, the nearest one before and after the
     * event per camera). Context stills are tagged `evidence_kind=context` so
     * the request, the AI and the operator can tell them apart.
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
            $event->team_id,
            self::SETTING_STILL_WINDOW,
            self::DEFAULT_STILL_WINDOW_MINUTES,
        ));

        $items = $mediaAdapter->listUploadedMedia(
            $integration,
            $externalAssetId,
            $occurredAt->copy()->subSeconds($windowSeconds),
            $occurredAt->copy()->addSeconds($windowSeconds),
            [...self::UPLOADED_TRIGGER_REASONS, ...self::CONTEXT_TRIGGER_REASONS],
        )['items'];

        $downloaded = 0;
        $alreadyStored = 0;
        $availableCount = 0;
        $outOfWindow = 0;
        $contextCandidates = [];

        foreach ($items as $item) {
            if ($item['status'] !== 'available' || ! is_string($item['url'] ?? null) || $item['url'] === '') {
                continue;
            }

            if (in_array($item['trigger_reason'] ?? null, self::CONTEXT_TRIGGER_REASONS, true)) {
                $contextCandidates[] = $item;

                continue;
            }

            if (! $this->capturedNearEvent($item, $occurredAt)) {
                $outOfWindow++;

                continue;
            }

            $availableCount++;

            $outcome = $this->downloadUploadedItem($event, $item, $storage);

            if ($outcome === MediaDownloadOutcome::Stored) {
                $downloaded++;
            } elseif ($outcome === MediaDownloadOutcome::AlreadyExists) {
                $alreadyStored++;
            }
        }

        $contextSelected = $this->nearestContextStills($contextCandidates, $occurredAt);

        foreach ($contextSelected as $item) {
            $outcome = $this->downloadUploadedItem($event, $item, $storage, ['evidence_kind' => 'context']);

            if ($outcome === MediaDownloadOutcome::Stored) {
                $downloaded++;
            } elseif ($outcome === MediaDownloadOutcome::AlreadyExists) {
                $alreadyStored++;
            }
        }

        SystemLog::ok(
            'media.deferred.sweep_completed',
            input: $this->logInput($request),
            calc: [
                'window_seconds' => $windowSeconds,
                'match_seconds' => self::UPLOADED_MATCH_SECONDS,
                'context_match_seconds' => self::CONTEXT_MATCH_SECONDS,
                'items_found' => count($items),
                'available' => $availableCount,
                'out_of_window' => $outOfWindow,
                'context_candidates' => count($contextCandidates),
                'context_selected' => count($contextSelected),
            ],
            result: ['downloaded' => $downloaded, 'already_stored' => $alreadyStored],
        );

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
     * @param  array{input: string|null, status: string, url: string|null, media_type?: string|null, trigger_reason?: string|null, start_time?: string|null}  $item
     * @param  array<string, mixed>  $extraMetadata
     */
    private function downloadUploadedItem(NormalizedEvent $event, array $item, ObjectStorage $storage, array $extraMetadata = []): MediaDownloadOutcome
    {
        $isVideo = str_starts_with($item['media_type'] ?? '', 'video');

        return $this->downloadMedia(
            $event,
            $item,
            $storage,
            $this->uploadedFilenameFor($item),
            $isVideo ? AttachmentType::Clip : AttachmentType::Snapshot,
            $isVideo ? 'video/mp4' : 'image/jpeg',
            'uploaded_media',
            $extraMetadata,
        );
    }

    /**
     * Per camera input, the context still captured closest before (or at) the
     * event and the closest after it, both within {@see CONTEXT_MATCH_SECONDS}.
     * Items without a capture instant cannot be placed and are dropped.
     *
     * @param  list<array{input: string|null, status: string, url: string|null, media_type?: string|null, trigger_reason?: string|null, start_time?: string|null}>  $candidates
     * @return list<array{input: string|null, status: string, url: string|null, media_type?: string|null, trigger_reason?: string|null, start_time?: string|null}>
     */
    private function nearestContextStills(array $candidates, Carbon $occurredAt): array
    {
        /** @var array<string, array{offset: int, item: array{input: string|null, status: string, url: string|null, media_type?: string|null, trigger_reason?: string|null, start_time?: string|null}}> $best */
        $best = [];

        foreach ($candidates as $item) {
            $startTime = $item['start_time'] ?? null;

            if (! is_string($startTime) || $startTime === '' || str_starts_with($item['media_type'] ?? '', 'video')) {
                continue;
            }

            $offset = (int) round(Carbon::parse($startTime)->getTimestamp() - $occurredAt->getTimestamp());

            if (abs($offset) > self::CONTEXT_MATCH_SECONDS) {
                continue;
            }

            $key = ($item['input'] ?? 'unknown').'|'.($offset <= 0 ? 'before' : 'after');

            if (! isset($best[$key]) || abs($offset) < abs($best[$key]['offset'])) {
                $best[$key] = ['offset' => $offset, 'item' => $item];
            }
        }

        return array_values(array_map(fn (array $pick): array => $pick['item'], $best));
    }

    /**
     * Whether an uploaded item was captured around this event (see
     * {@see UPLOADED_MATCH_SECONDS}). Items without a capture instant cannot
     * be told apart, so they keep the old window-wide behaviour.
     *
     * @param  array{start_time?: string|null}  $item
     */
    private function capturedNearEvent(array $item, Carbon $occurredAt): bool
    {
        $startTime = $item['start_time'] ?? null;

        if (! is_string($startTime) || $startTime === '') {
            return true;
        }

        return abs(Carbon::parse($startTime)->diffInSeconds($occurredAt, false)) <= self::UPLOADED_MATCH_SECONDS;
    }

    /**
     * Keep a request alive as a sweep-only poll: no retrieval will ever be
     * placed (either the request is explicitly sweep-only, or the asset reports
     * no paired camera and the provider flag can be stale) — re-sweep the
     * uploaded media until evidence lands or the request's `expires_at` closes
     * it. The request completes once event evidence backs the event, or once
     * context stills do and {@see CONTEXT_SETTLE_SECONDS} passed without the
     * event's own clip.
     *
     * `$escalate` (explicit sweep-only requests) lets an emergency without any
     * upload place its paid fallback retrieval; it returns true when it did,
     * and the retrieval's own poll chain takes over.
     *
     * @param  (Closure(): bool)|null  $escalate
     */
    private function continueSweepOnly(
        EventMediaRequest $request,
        NormalizedEvent $event,
        RefreshContextMediaSnapshot $refreshSnapshot,
        string $fulfilledReason,
        string $reasonCode,
        ?Closure $escalate = null,
    ): void {
        $evidence = $this->uploadedEvidence($event);
        $requestAgeSeconds = $this->requestAgeSeconds($request);

        if ($evidence['event']) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, $fulfilledReason, $reasonCode);
            $refreshSnapshot->execute($event->id);

            return;
        }

        if ($evidence['context'] && $requestAgeSeconds >= self::CONTEXT_SETTLE_SECONDS) {
            $this->closeWithoutNewMedia($request, $event, MediaRequestStatus::Failed, 'Request fulfilled by the context stills the dashcam took around the event.', 'fulfilled_by_context', [
                'request_age_seconds' => $requestAgeSeconds,
                'settle_seconds' => self::CONTEXT_SETTLE_SECONDS,
            ]);
            $refreshSnapshot->execute($event->id);

            return;
        }

        $emergency = $this->isEmergency($event);
        $fallbackPending = $emergency
            && $escalate !== null
            && ! array_key_exists('emergency_fallback', $request->response_metadata_json ?? []);

        if ($fallbackPending && $requestAgeSeconds >= self::EMERGENCY_FALLBACK_AFTER_SECONDS) {
            if ($escalate()) {
                return;
            }

            $fallbackPending = false;
        }

        $request->forceFill(['status' => MediaRequestStatus::Processing])->save();
        $refreshSnapshot->execute($event->id);

        // While an emergency may still escalate, poll at retrieval pace so the
        // fallback lands right after its grace window.
        $delay = $fallbackPending ? self::POLL_DELAY_SECONDS : self::SWEEP_POLL_DELAY_SECONDS;

        SystemLog::ok(
            'media.deferred.sweep_polling',
            input: $this->logInput($request),
            calc: [
                'next_poll_seconds' => $delay,
                'emergency' => $emergency,
                'has_context_stills' => $evidence['context'],
                'request_age_seconds' => $requestAgeSeconds,
            ],
        );

        self::dispatch($request->id)->delay(now()->addSeconds($delay));
    }

    /**
     * The paid fallback for an emergency the dashcam uploaded nothing for: one
     * high-res clip of both cameras around the event. Decided once per
     * request (`emergency_fallback` in the metadata records the outcome), so a
     * skipped or rejected fallback never re-fires on every poll. Returns true
     * when the retrieval was placed.
     */
    private function placeEmergencyFallback(
        EventMediaRequest $request,
        NormalizedEvent $event,
        TenantIntegration $integration,
        string $externalAssetId,
        MediaRetrievalAdapter $mediaAdapter,
        TenantConfigResolver $tenantConfig,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): bool {
        $metadata = $request->response_metadata_json ?? [];
        $retention = $this->footageRetention($request, $event, $tenantConfig);

        $skipReason = match (true) {
            $retention['expired'] => 'older_than_footage_retention',
            $this->assetReportsNoCamera($event) => 'asset_reports_no_camera',
            default => null,
        };

        if ($skipReason !== null) {
            $metadata['emergency_fallback'] = false;
            $request->forceFill(['response_metadata_json' => $metadata])->save();

            SystemLog::skipped('media.deferred.emergency_fallback_skipped', reason: $skipReason, input: $this->logInput($request), calc: [
                'event_age_hours' => $retention['event_age_hours'],
                'max_age_hours' => $retention['max_age_hours'],
            ]);

            return false;
        }

        $occurredAt = Carbon::instance($event->occurred_at ?? $request->requested_at ?? now());

        $windowSeconds = min(self::MAX_CLIP_WINDOW_SECONDS, max(1, (int) $tenantConfig->resolve(
            $event->team_id,
            self::SETTING_CLIP_WINDOW,
            self::DEFAULT_CLIP_WINDOW_SECONDS,
        )));

        $inputs = $this->inputsFor(MediaRequestType::FetchVideoClip);

        $retrievalId = $mediaAdapter->requestMedia(
            $integration,
            $externalAssetId,
            $occurredAt->copy()->subSeconds($windowSeconds),
            $occurredAt->copy()->addSeconds($windowSeconds),
            $inputs,
        );

        if ($retrievalId === null) {
            $metadata['emergency_fallback'] = false;
            $request->forceFill(['response_metadata_json' => $metadata])->save();

            SystemLog::skipped('media.deferred.emergency_fallback_skipped', reason: 'provider_rejected_retrieval', input: $this->logInput($request));

            return false;
        }

        $metadata['retrieval_id'] = $retrievalId;
        $metadata['clip_window_seconds'] = $windowSeconds;
        $metadata['emergency_fallback'] = true;

        $request->forceFill([
            'status' => MediaRequestStatus::Sent,
            'response_metadata_json' => $metadata,
        ])->save();

        $refreshSnapshot->execute($event->id);

        SystemLog::ok(
            'media.deferred.emergency_fallback_placed',
            input: $this->logInput($request),
            calc: [
                'grace_seconds' => self::EMERGENCY_FALLBACK_AFTER_SECONDS,
                'request_age_seconds' => $this->requestAgeSeconds($request),
                'clip_window_seconds' => $windowSeconds,
                'inputs' => $inputs,
                'next_poll_seconds' => self::POLL_DELAY_SECONDS,
            ],
        );

        self::dispatch($request->id)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));

        return true;
    }

    /**
     * @return array{expired: bool, event_age_hours: int, max_age_hours: int}
     */
    private function footageRetention(EventMediaRequest $request, NormalizedEvent $event, TenantConfigResolver $tenantConfig): array
    {
        $maxAgeHours = max(1, (int) $tenantConfig->resolve(
            $event->team_id,
            self::SETTING_RETRIEVAL_MAX_AGE,
            self::DEFAULT_RETRIEVAL_MAX_AGE_HOURS,
        ));

        $occurredAt = Carbon::instance($event->occurred_at ?? $request->requested_at ?? now());

        return [
            'expired' => $occurredAt->lt(now()->subHours($maxAgeHours)),
            'event_age_hours' => (int) $occurredAt->diffInHours(now(), true),
            'max_age_hours' => $maxAgeHours,
        ];
    }

    private function isEmergency(NormalizedEvent $event): bool
    {
        $event->loadMissing(['eventCategory', 'eventType']);

        return NormalizeRawEvent::isEmergencyCode($event->eventCategory?->code, $event->eventType?->code);
    }

    private function requestAgeSeconds(EventMediaRequest $request): int
    {
        $since = $request->requested_at ?? $request->created_at ?? now();

        return (int) max(0, $since->diffInSeconds(now(), false));
    }

    /**
     * Only an explicit `has_camera = false` from the provider sync counts:
     * assets synced before the flag existed keep the retrieval path.
     */
    private function assetReportsNoCamera(NormalizedEvent $event): bool
    {
        if ($event->asset_id === null) {
            return false;
        }

        $metadata = Asset::query()->find($event->asset_id)?->metadata_json;

        return is_array($metadata)
            && array_key_exists('has_camera', $metadata)
            && $metadata['has_camera'] === false;
    }

    /**
     * Which kinds of uploaded media already back the event: `event` = its own
     * clip/stills (panic, safety, prior retrieval), `context` = the dashcam's
     * periodic/trip stills around it.
     *
     * @return array{event: bool, context: bool}
     */
    private function uploadedEvidence(NormalizedEvent $event): array
    {
        $kinds = RawEventAttachment::query()
            ->where('raw_event_id', $event->raw_event_id)
            ->where('metadata_json->source', 'uploaded_media')
            ->get(['metadata_json'])
            ->map(fn (RawEventAttachment $attachment): string => ($attachment->metadata_json['evidence_kind'] ?? null) === 'context' ? 'context' : 'event');

        return [
            'event' => $kinds->contains('event'),
            'context' => $kinds->contains('context'),
        ];
    }

    private function hasUploadedEvidence(NormalizedEvent $event): bool
    {
        return in_array(true, $this->uploadedEvidence($event), true);
    }

    /**
     * Close a request whose retrieval path delivered nothing: when the event
     * already holds auto-uploaded evidence the request completes (the alert IS
     * backed by media), otherwise it fails/expires as before.
     *
     * @param  array<string, mixed>  $calc
     */
    private function closeWithoutNewMedia(
        EventMediaRequest $request,
        NormalizedEvent $event,
        MediaRequestStatus $status,
        string $reason,
        string $reasonCode,
        array $calc = [],
    ): void {
        if (! $this->hasUploadedEvidence($event)) {
            $this->markFailed($request, $status, $reason, $reasonCode, $calc);

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

        SystemLog::ok(
            'media.deferred.closed',
            input: $this->logInput($request),
            calc: $calc,
            result: ['status' => 'completed', 'completed_via' => 'uploaded_media', 'close_reason' => $reasonCode],
        );
    }

    /**
     * @return array{event_media_request_id: int, normalized_event_id: int}
     */
    private function logInput(EventMediaRequest $request): array
    {
        return [
            'event_media_request_id' => $request->id,
            'normalized_event_id' => $request->normalized_event_id,
        ];
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

        // El nombre es la clave de dedupe en storage: conserva la lectura
        // falsy de siempre (null, '' y '0' caen en 'unknown').
        $trigger = preg_replace('/[^a-zA-Z0-9]+/', '', $item['trigger_reason'] ?? 'unknown');
        $trigger = $trigger === null || $trigger === '' || $trigger === '0' ? 'unknown' : $trigger;

        $extension = str_starts_with($item['media_type'] ?? '', 'video') ? 'mp4' : 'jpg';

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
     * Fetch one provider media item into storage through the hardened
     * downloader (https + host allowlist, streamed, size-capped). Tri-state so
     * a poll can tell "nothing new" apart from "download failed, retry".
     *
     * @param  array{input: string|null, status: string, url: string|null, offset_seconds?: int, trigger_reason?: string|null, start_time?: string|null}  $item
     * @param  array<string, mixed>  $extraMetadata
     */
    private function downloadMedia(
        NormalizedEvent $event,
        array $item,
        ObjectStorage $storage,
        string $filename,
        AttachmentType $type,
        string $defaultMimeType,
        string $source = 'deferred_retrieval',
        array $extraMetadata = [],
    ): MediaDownloadOutcome {
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
                    'mime_type' => $this->resolveMimeType($storage->mimeType($storagePath), $filename, $defaultMimeType),
                    'size_bytes' => $storage->size($storagePath) ?? 0,
                    'metadata_json' => ['source' => $source, 'input' => $item['input'], ...$extraMetadata],
                ],
            );

            return MediaDownloadOutcome::AlreadyExists;
        }

        try {
            $download = app(SecureMediaDownloader::class)->download((string) $item['url']);
        } catch (MediaDownloadException $e) {
            SystemLog::degraded('media.deferred.download_failed', reason: 'download_failed', input: ['normalized_event_id' => $event->id, 'camera_input' => $item['input']], error: $e);

            return MediaDownloadOutcome::Failed;
        }

        $mimeType = $this->resolveMimeType($download->contentType, $filename, $defaultMimeType);

        try {
            $stream = $download->stream();

            try {
                $storage->put($storagePath, $stream, [
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

        $metadata = ['source' => $source, 'input' => $item['input'], ...$extraMetadata];

        if (array_key_exists('offset_seconds', $item)) {
            $metadata['offset_seconds'] = $item['offset_seconds'];
        }

        // Misma lectura que el `! empty()` de antes: null, '' y '0' no se anotan.
        $triggerReason = $item['trigger_reason'] ?? null;

        if ($triggerReason !== null && $triggerReason !== '' && $triggerReason !== '0') {
            $metadata['trigger_reason'] = $triggerReason;
        }

        // Capture instant of uploaded media: lets the vision model know how
        // far from the event the frame was taken.
        if (is_string($item['start_time'] ?? null) && $item['start_time'] !== '') {
            $metadata['start_time'] = $item['start_time'];
        }

        RawEventAttachment::firstOrCreate(
            [
                'raw_event_id' => $event->raw_event_id,
                'storage_path' => $storagePath,
            ],
            [
                'attachment_type' => $type,
                'mime_type' => $mimeType,
                'size_bytes' => $download->size,
                'metadata_json' => $metadata,
            ],
        );

        return MediaDownloadOutcome::Stored;
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
        if ($event->asset_id === null) {
            return null;
        }

        $references = AssetExternalReference::query()
            ->where('asset_id', $event->asset_id)
            ->whereNotNull('external_id')
            ->get();

        foreach ($references as $reference) {
            $integration = TenantIntegration::query()
                ->where('team_id', $event->team_id)
                ->where('provider_id', $reference->provider_id)
                ->where('status', TenantIntegrationStatus::Active)
                ->first();

            if ($integration !== null) {
                return [$integration, $reference->external_id];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $calc
     */
    private function markFailed(EventMediaRequest $request, MediaRequestStatus $status, string $reason, string $reasonCode, array $calc = []): void
    {
        $request->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();

        EventMediaFailed::dispatch($request, $reason);

        SystemLog::skipped(
            'media.deferred.closed',
            reason: $reasonCode,
            input: $this->logInput($request),
            calc: $calc,
            result: ['status' => $status->value, 'completed_via' => null],
        );
    }

    /**
     * Samsara serves downloads as `binary/octet-stream`; persisting that
     * verbatim makes the multimodal agent refuse every file. Prefer a real
     * reported mime, then the filename extension, then the per-type default.
     */
    private function resolveMimeType(?string $reported, string $filename, string $default): string
    {
        $bare = strtolower(trim(explode(';', (string) $reported)[0]));

        if ($bare !== '' && $bare !== 'binary/octet-stream' && $bare !== 'application/octet-stream') {
            return $bare;
        }

        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => $default,
        };
    }

    public function failed(Throwable $exception): void
    {
        $request = EventMediaRequest::withoutGlobalScopes()->find($this->eventMediaRequestId);

        if ($request !== null && $request->status->isInFlight()) {
            $request->forceFill([
                'status' => MediaRequestStatus::Failed,
                'completed_at' => now(),
            ])->save();

            EventMediaFailed::dispatch($request, SafeErrorMessage::from($exception));
        }

        JobFailureReporter::report(static::class, $exception, ['event_media_request_id' => $this->eventMediaRequestId]);
    }
}
