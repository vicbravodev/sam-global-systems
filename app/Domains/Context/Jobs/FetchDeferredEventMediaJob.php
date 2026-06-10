<?php

namespace App\Domains\Context\Jobs;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Contracts\ObjectStorage;
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
 */
class FetchDeferredEventMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int POLL_DELAY_SECONDS = 60;

    /** Capture window around the event timestamp, per side. */
    public const int CLIP_WINDOW_SECONDS = 30;

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
    ): void {
        $request = EventMediaRequest::withoutGlobalScopes()->find($this->eventMediaRequestId);

        if ($request === null || ! $request->status->isInFlight()) {
            return;
        }

        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            $this->markFailed($request, MediaRequestStatus::Expired, 'Media retrieval window expired before the provider delivered the media.');
            $refreshSnapshot->execute($request->normalized_event_id);

            return;
        }

        $event = NormalizedEvent::withoutGlobalScopes()->find($request->normalized_event_id);

        if ($event === null) {
            $this->markFailed($request, MediaRequestStatus::Failed, 'Normalized event no longer exists.');

            return;
        }

        $resolved = $this->resolveIntegration($event);

        if ($resolved === null) {
            $this->markFailed($request, MediaRequestStatus::Failed, 'No active integration with an external asset reference can serve this media request.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        [$integration, $externalAssetId] = $resolved;

        $metadata = $request->response_metadata_json ?? [];
        $retrievalId = $metadata['retrieval_id'] ?? null;

        if (! is_string($retrievalId) || $retrievalId === '') {
            $this->placeRetrieval($request, $event, $integration, $externalAssetId, $mediaAdapter, $refreshSnapshot);

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
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        $occurredAt = Carbon::instance($event->occurred_at ?? $request->requested_at ?? now());

        $retrievalId = $mediaAdapter->requestMedia(
            $integration,
            $externalAssetId,
            $occurredAt->copy()->subSeconds(self::CLIP_WINDOW_SECONDS),
            $occurredAt->copy()->addSeconds(self::CLIP_WINDOW_SECONDS),
            $this->inputsFor($request->request_type),
        );

        if ($retrievalId === null) {
            $this->markFailed($request, MediaRequestStatus::Failed, 'Provider rejected the media retrieval request.');
            $refreshSnapshot->execute($event->id);

            return;
        }

        $metadata = $request->response_metadata_json ?? [];
        $metadata['retrieval_id'] = $retrievalId;

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

        foreach ($available as $item) {
            $this->downloadClip($event, $item, $storage);
        }

        if ($available !== []) {
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
            $this->markFailed($request, MediaRequestStatus::Failed, 'Provider reported every requested clip as failed.');
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
     * @param  array{input: string|null, status: string, url: string|null}  $item
     */
    private function downloadClip(NormalizedEvent $event, array $item, ObjectStorage $storage): void
    {
        try {
            $response = Http::timeout((int) config('services.samsara.media_download_timeout', 30))->get((string) $item['url']);
        } catch (\Throwable $e) {
            Log::warning('Deferred media clip download failed', [
                'normalized_event_id' => $event->id,
                'input' => $item['input'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || $response->body() === '') {
            return;
        }

        $filename = 'deferred-'.$this->filenameFor($item['input']);
        $storagePath = "teams/{$event->team_id}/raw-events/{$event->raw_event_id}/{$filename}";
        $mimeType = $response->header('Content-Type') ?: 'video/mp4';

        if (! $storage->exists($storagePath)) {
            $storage->put($storagePath, $response->body(), [
                'visibility' => 'private',
                'ContentType' => $mimeType,
            ]);
        }

        RawEventAttachment::firstOrCreate(
            [
                'raw_event_id' => $event->raw_event_id,
                'storage_path' => $storagePath,
            ],
            [
                'attachment_type' => AttachmentType::Clip,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($response->body()),
                'metadata_json' => ['source' => 'deferred_retrieval', 'input' => $item['input']],
            ],
        );
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

    private function filenameFor(?string $input): string
    {
        return match ($input) {
            'dashcamRoadFacing' => 'road-facing.mp4',
            'dashcamDriverFacing' => 'driver-facing.mp4',
            default => 'clip-'.substr(md5((string) $input), 0, 8).'.mp4',
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
