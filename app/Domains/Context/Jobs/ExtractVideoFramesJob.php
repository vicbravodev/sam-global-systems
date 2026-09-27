<?php

namespace App\Domains\Context\Jobs;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaAvailabilityStatus;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaRole;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Support\VideoFrameExtractor;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\FileObject;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Saca fotogramas clave de un clip para que el modelo de visión (que sólo
 * acepta imágenes) pueda evaluarlo. Cada fotograma se guarda junto al clip,
 * se registra como `EventMediaContext` de tipo Snapshot (+ FileObject) y
 * dispara `EventMediaAvailable` igual que una foto: el pipeline multimodal
 * existente lo recoge sin cambios.
 *
 * Idempotente: las rutas de los fotogramas son deterministas y se reutilizan
 * las filas existentes; si ya están todas, no vuelve a invocar ffmpeg.
 */
class ExtractVideoFramesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $mediaContextId,
        public readonly int $teamId,
    ) {
        $this->onQueue('context');
    }

    public function uniqueId(): string
    {
        return $this->teamId.':'.$this->mediaContextId;
    }

    public function handle(
        VideoFrameExtractor $extractor,
        ObjectStorage $storage,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        // Lookup de entrada sin scope (así descubre su tenant), validando que
        // el id de recurso y el team del payload concuerdan. Ver §2.1.
        $clip = EventMediaContext::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->find($this->mediaContextId);

        if ($clip === null) {
            return;
        }

        TenantContext::set($clip->team_id);

        if (! in_array($clip->media_type, [MediaType::Clip, MediaType::Video], true) || $clip->storage_path === null) {
            return;
        }

        $event = NormalizedEvent::query()
            ->where('team_id', $clip->team_id)
            ->find($clip->normalized_event_id);

        if ($event === null) {
            return;
        }

        $positions = (array) config('media-frames.positions', [0.1, 0.5, 0.9]);

        if ($this->existingFrames($clip)->count() >= max(1, count($positions))) {
            return;
        }

        if (! $extractor->isAvailable()) {
            Log::warning('ExtractVideoFramesJob: ffmpeg no disponible; el clip no llegará al modelo de visión', [
                'media_context_id' => $clip->id,
                'ffmpeg_binary' => $extractor->binary(),
            ]);

            return;
        }

        $contents = $storage->get($clip->storage_path);

        if ($contents === null || $contents === '') {
            return;
        }

        $frames = $extractor->extract(
            $contents,
            $clip->duration_seconds !== null ? (float) $clip->duration_seconds : null,
            pathinfo($clip->storage_path, PATHINFO_EXTENSION) ?: 'mp4',
        );

        $created = 0;

        foreach ($frames as $index => $frame) {
            if ($this->storeFrame($storage, $clip, $event, $index, $frame['offset_seconds'], $frame['contents'])) {
                $created++;
            }
        }

        if ($created > 0) {
            $refreshSnapshot->execute($event->id);
        }

        Log::info('ExtractVideoFramesJob processed clip', [
            'media_context_id' => $clip->id,
            'frames_extracted' => count($frames),
            'frames_created' => $created,
        ]);
    }

    /**
     * @return Collection<int, EventMediaContext>
     */
    private function existingFrames(EventMediaContext $clip)
    {
        return EventMediaContext::query()
            ->where('team_id', $clip->team_id)
            ->where('normalized_event_id', $clip->normalized_event_id)
            ->where('storage_path', 'like', $this->framePathPrefix($clip).'%')
            ->get();
    }

    private function framePathPrefix(EventMediaContext $clip): string
    {
        $path = (string) $clip->storage_path;
        $directory = trim(dirname($path), './');
        $basename = pathinfo($path, PATHINFO_FILENAME);

        return ($directory !== '' ? $directory.'/' : '').'frames/'.$basename.'-frame-';
    }

    private function storeFrame(
        ObjectStorage $storage,
        EventMediaContext $clip,
        NormalizedEvent $event,
        int $index,
        float $offsetSeconds,
        string $contents,
    ): bool {
        $path = $this->framePathPrefix($clip).$index.'.jpg';

        if (! $storage->exists($path)) {
            $storage->put($path, $contents, [
                'visibility' => 'private',
                'ContentType' => 'image/jpeg',
            ]);
        }

        $size = $storage->size($path) ?? strlen($contents);

        $fileObject = FileObject::query()->firstOrCreate(
            [
                'bucket' => config('filesystems.disks.rustfs.bucket', 'sam'),
                'object_key' => $path,
            ],
            [
                'team_id' => $clip->team_id,
                'original_filename' => basename($path),
                'size_bytes' => $size,
                'content_type' => 'image/jpeg',
                'visibility' => 'private',
                'category' => 'media',
                'metadata_json' => [
                    'source' => 'video_frame',
                    'parent_media_context_id' => $clip->id,
                ],
            ],
        );

        $parentMetadata = is_array($clip->metadata_json) ? $clip->metadata_json : [];

        $frame = EventMediaContext::query()->firstOrCreate(
            [
                'normalized_event_id' => $event->id,
                'storage_path' => $path,
            ],
            [
                'team_id' => $clip->team_id,
                'asset_id' => $clip->asset_id,
                'provider_id' => $clip->provider_id,
                'file_object_id' => $fileObject->id,
                'source_attachment_id' => $clip->source_attachment_id,
                'media_type' => MediaType::Snapshot,
                'media_role' => $clip->media_role ?? MediaRole::SupportingEvidence,
                'mime_type' => 'image/jpeg',
                'size_bytes' => $size,
                'captured_at' => $clip->captured_at?->copy()->addMilliseconds((int) round($offsetSeconds * 1000)),
                'availability_status' => MediaAvailabilityStatus::Available,
                'retrieval_status' => MediaRetrievalStatus::Ready,
                'metadata_json' => array_filter([
                    'source' => 'video_frame',
                    'parent_media_context_id' => $clip->id,
                    'offset_seconds' => $offsetSeconds,
                    // Lado de cámara heredado del clip (road/driver facing).
                    'input' => $parentMetadata['input'] ?? null,
                    'camera_role' => $parentMetadata['camera_role'] ?? null,
                ], static fn ($value): bool => $value !== null),
            ],
        );

        if ($fileObject->fileable_id === null) {
            $fileObject->forceFill([
                'fileable_type' => EventMediaContext::class,
                'fileable_id' => $frame->id,
            ])->save();
        }

        if ($frame->wasRecentlyCreated) {
            EventMediaAvailable::dispatch($frame, $event);

            return true;
        }

        return false;
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ExtractVideoFramesJob failed', [
            'media_context_id' => $this->mediaContextId,
            'error' => $exception->getMessage(),
        ]);
    }
}
