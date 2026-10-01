<?php

namespace App\Domains\Context\Support;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Jobs\ExtractVideoFramesJob;
use App\Domains\Context\Models\EventMediaContext;
use App\Support\SystemLog;
use Illuminate\Support\Collection;

/**
 * How an event's media is shown to people (incident detail, inbox preview,
 * Copilot): one entry per file the camera actually produced.
 *
 * Frames that {@see ExtractVideoFramesJob} cuts out
 * of a clip are media rows too (the vision model assesses them), but listing
 * them next to the clip reads as extra photos — a panic with 2 photos and 2
 * clips showed up as "8 imágenes". Here they fold under their clip: the first
 * frame becomes the clip's thumbnail (browsers cannot preview an mp4 in an
 * `<img>`) and their ids travel along so the AI's verdicts on them still
 * reach the clip.
 */
final class EventMediaGallery
{
    public const int URL_TTL_MINUTES = 30;

    public function __construct(private readonly ObjectStorage $storage) {}

    /**
     * @param  Collection<int, EventMediaContext>  $media
     * @return list<array{media: EventMediaContext, url: string|null, thumbnailUrl: string|null, frameIds: list<int>}>
     */
    public function entries(Collection $media): array
    {
        $ids = $media->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $isFrame = fn (EventMediaContext $item): bool => self::parentClipId($item) !== null
            && in_array(self::parentClipId($item), $ids, true);

        $framesByClip = $media
            ->filter($isFrame)
            ->sortBy(fn (EventMediaContext $frame): float => (float) ($frame->metadata_json['offset_seconds'] ?? 0))
            ->groupBy(fn (EventMediaContext $frame): int => (int) self::parentClipId($frame));

        $entries = $media
            ->reject($isFrame)
            ->map(function (EventMediaContext $item) use ($framesByClip): array {
                $frames = $framesByClip->get((int) $item->id, collect());
                $firstFrame = $frames->first();

                return [
                    'media' => $item,
                    'url' => $this->urlFor($item),
                    'thumbnailUrl' => $item->thumbnail_url
                        ?? ($firstFrame instanceof EventMediaContext ? $this->urlFor($firstFrame) : null),
                    'frameIds' => array_values($frames->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                ];
            })
            ->all();

        return array_values($entries);
    }

    public static function isVideo(EventMediaContext $media): bool
    {
        return in_array($media->media_type?->value, ['video', 'clip'], true)
            || str_starts_with((string) $media->mime_type, 'video/');
    }

    /**
     * The provider URL when the row still points at one, otherwise a
     * short-lived signed URL to our copy in object storage.
     */
    public function urlFor(EventMediaContext $media): ?string
    {
        if ($media->media_url !== null) {
            return $media->media_url;
        }

        if ($media->storage_path === null) {
            return null;
        }

        try {
            return $this->storage->temporaryUrl($media->storage_path, now()->addMinutes(self::URL_TTL_MINUTES));
        } catch (\Throwable $e) {
            SystemLog::degraded('context.media.url_unavailable', reason: 'signing_failed', input: [
                'event_media_context_id' => $media->id,
                'normalized_event_id' => $media->normalized_event_id,
            ], error: $e);

            return null;
        }
    }

    private static function parentClipId(EventMediaContext $media): ?int
    {
        $metadata = is_array($media->metadata_json) ? $media->metadata_json : [];

        if (($metadata['source'] ?? null) !== 'video_frame' || ! isset($metadata['parent_media_context_id'])) {
            return null;
        }

        return (int) $metadata['parent_media_context_id'];
    }
}
