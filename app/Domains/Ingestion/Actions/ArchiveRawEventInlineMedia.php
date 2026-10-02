<?php

namespace App\Domains\Ingestion\Actions;

use App\Contracts\ObjectStorage;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Jobs\ArchiveRawEventMediaJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Infrastructure\Storage\MediaDownloadException;
use App\Infrastructure\Storage\SecureMediaDownloader;
use App\Support\ObjectStorageFailure;
use App\Support\SystemLog;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Copia a nuestro storage la media inline de un safety event: las URLs del
 * payload son prefirmadas y caducan, así que se bajan en cuanto llega el
 * evento. Las URLs se leen del `payload_json` ya persistido, nunca de otro
 * sitio: el reintento diferido ({@see ArchiveRawEventMediaJob})
 * no guarda la URL en ningún lado nuevo.
 *
 * Idempotente por fila: un archivo cuyo `RawEventAttachment` ya existe no se
 * vuelve a bajar. Si el storage de objetos falla (RustFS/S3 caído, timeout,
 * 5xx) se detiene en ese archivo y lo devuelve en `storage_unavailable`: el
 * llamador decide diferirlo; el evento nunca depende de esto.
 */
class ArchiveRawEventInlineMedia
{
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
        private ObjectStorage $storage,
        private SecureMediaDownloader $downloader,
    ) {}

    /**
     * @return array{found: int, downloaded: int, already_stored: int, failed: int, storage_unavailable: bool, storage_error: Throwable|null}
     */
    public function execute(RawEvent $rawEvent): array
    {
        $result = ['found' => 0, 'downloaded' => 0, 'already_stored' => 0, 'failed' => 0, 'storage_unavailable' => false, 'storage_error' => null];

        foreach ($this->mediaItems($rawEvent) as $item) {
            $result['found']++;

            $storagePath = "teams/{$rawEvent->team_id}/raw-events/{$rawEvent->id}/{$item['filename']}";

            if (RawEventAttachment::query()->where('raw_event_id', $rawEvent->id)->where('storage_path', $storagePath)->exists()) {
                $result['already_stored']++;

                continue;
            }

            try {
                $this->storeMediaDownload($rawEvent, $item['url'], $storagePath, $item['metadata']) ? $result['downloaded']++ : $result['failed']++;
            } catch (Throwable $e) {
                if (! ObjectStorageFailure::matches($e)) {
                    throw $e;
                }

                // Storage caído: no tiene sentido seguir con el resto de
                // archivos de este evento; se reintentan todos juntos.
                $result['storage_unavailable'] = true;
                $result['storage_error'] = $e;

                break;
            }
        }

        return $result;
    }

    /**
     * Cuántas URLs de media trae el payload, sin bajar nada.
     */
    public function countMediaUrls(RawEvent $rawEvent): int
    {
        return count($this->mediaItems($rawEvent));
    }

    /**
     * @return list<array{url: string, filename: string, metadata: array<string, mixed>}>
     */
    private function mediaItems(RawEvent $rawEvent): array
    {
        $payload = $rawEvent->payload_json;
        $items = [];

        // Legacy safety-event shape: top-level download URLs.
        foreach (self::MEDIA_URL_KEYS as $key => $filename) {
            $url = Arr::get($payload, $key);

            if (! is_string($url) || $url === '') {
                continue;
            }

            $items[] = ['url' => $url, 'filename' => $filename, 'metadata' => ['source_url_key' => $key]];
        }

        // Stream v2 shape (`GET /safety-events/stream`): a `media` array with
        // one `{input, url, cameraRole}` item per camera stream.
        foreach ((array) Arr::get($payload, 'media', []) as $index => $media) {
            $media = (array) $media;
            $url = $media['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $items[] = [
                'url' => $url,
                'filename' => sprintf('media-%d-%s.mp4', (int) $index, $this->mediaInputSlug($media['input'] ?? null)),
                // Valores del payload de Samsara (mixed): se descartan los
                // "vacíos" de PHP igual que el array_filter sin callback.
                'metadata' => array_filter([
                    'source_url_key' => "media.{$index}.url",
                    'input' => $media['input'] ?? null,
                    'camera_role' => $media['cameraRole'] ?? null,
                ], static fn (mixed $value): bool => ! in_array($value, [null, false, 0, 0.0, '', '0', []], true)),
            ];
        }

        return $items;
    }

    /**
     * Download through the hardened downloader (https + host allowlist,
     * streamed to a temp file, size-capped) and store as a raw attachment.
     * Storage failures propagate to {@see execute()}.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function storeMediaDownload(RawEvent $rawEvent, string $url, string $storagePath, array $metadata): bool
    {
        try {
            $download = $this->downloader->download($url);
        } catch (MediaDownloadException $e) {
            SystemLog::degraded('ingestion.media.inline_download_failed', reason: 'download_failed', input: ['raw_event_id' => $rawEvent->id, 'url_key' => $metadata['source_url_key'] ?? null], error: $e);

            return false;
        }

        $mimeType = self::isBlank($download->contentType) ? 'video/mp4' : $download->contentType;

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

    private function mediaInputSlug(mixed $input): string
    {
        $input = is_string($input) ? $input : null;

        return match ($input) {
            'dashcamRoadFacing' => 'road-facing',
            'dashcamDriverFacing' => 'driver-facing',
            default => self::slugOrUnknown(self::isBlank($input) ? 'unknown' : $input),
        };
    }

    /**
     * `null`, '' y '0' cuentan como "sin valor" (la truthiness de string que
     * usaba el `?:` original).
     *
     * @phpstan-assert-if-false non-falsy-string $value
     */
    private static function isBlank(?string $value): bool
    {
        return $value === null || $value === '' || $value === '0';
    }

    private static function slugOrUnknown(string $input): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($input));

        return self::isBlank($slug) ? 'unknown' : $slug;
    }
}
