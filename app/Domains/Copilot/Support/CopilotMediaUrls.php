<?php

namespace App\Domains\Copilot\Support;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Support\SystemLog;
use Throwable;

/**
 * Browser URLs of camera media shown in Copilot cards: the provider's own
 * http(s) URL when there is one, else a short-lived signed URL of our
 * storage. Stored answers keep the URL of the moment they were written, so
 * a reopened conversation re-resolves them here (per tenant) instead of
 * serving expired signatures.
 */
final class CopilotMediaUrls
{
    public const TTL_MINUTES = 30;

    public function __construct(private readonly ObjectStorage $storage) {}

    public function url(EventMediaContext $media): ?string
    {
        if ($media->media_url !== null) {
            return $media->media_url;
        }

        if ($media->storage_path === null) {
            return null;
        }

        try {
            return $this->storage->temporaryUrl($media->storage_path, now()->addMinutes(self::TTL_MINUTES));
        } catch (Throwable $e) {
            SystemLog::degraded('copilot.media.sign_failed', 'signing_failed', [
                'team_id' => $media->team_id,
                'event_media_context_id' => $media->id,
            ], error: $e);

            return null;
        }
    }

    /**
     * Images are their own thumbnail (same URL). Video only gets one when
     * the provider gave a real http(s) poster; never a storage path.
     */
    public function thumbnail(EventMediaContext $media, ?string $url): ?string
    {
        if (in_array($media->media_type, [MediaType::Image, MediaType::Snapshot], true)) {
            return $url;
        }

        $thumbnail = $media->thumbnail_url;

        return is_string($thumbnail) && preg_match('#^https?://#i', $thumbnail) === 1 ? $thumbnail : null;
    }

    /**
     * Re-resolves `url` / `thumbnailUrl` of every media card item from the
     * tenant's own media rows (`thumbnailMediaId`: the clip frame used as poster). Stored URLs are never trusted: an item whose
     * row is gone (or belongs to another tenant) loses both.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public function refreshBlocks(array $blocks, int $teamId, ?int $messageId = null): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'media') {
                foreach ((array) ($block['items'] ?? []) as $item) {
                    if (is_array($item) && is_numeric($item['id'] ?? null)) {
                        $ids[] = (int) $item['id'];
                    }

                    if (is_array($item) && is_numeric($item['thumbnailMediaId'] ?? null)) {
                        $ids[] = (int) $item['thumbnailMediaId'];
                    }
                }
            }
        }

        if ($ids === []) {
            return $blocks;
        }

        $media = EventMediaContext::query()
            ->where('team_id', $teamId)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');

        $missing = 0;

        foreach ($blocks as $b => $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'media') {
                continue;
            }

            foreach ((array) ($block['items'] ?? []) as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $row = $media->get((int) ($item['id'] ?? 0));

                if ($row === null) {
                    $missing++;
                }

                $url = $row !== null ? $this->url($row) : null;
                $blocks[$b]['items'][$i]['url'] = $url;
                $thumbnail = $row !== null ? $this->thumbnail($row, $url) : null;

                // A clip without its own poster shows its first extracted frame.
                if ($row !== null && $thumbnail === null && is_numeric($item['thumbnailMediaId'] ?? null)) {
                    $frame = $media->get((int) $item['thumbnailMediaId']);
                    $thumbnail = $frame !== null ? $this->url($frame) : null;
                }

                $blocks[$b]['items'][$i]['thumbnailUrl'] = $thumbnail;
            }
        }

        if ($missing > 0) {
            SystemLog::skipped('copilot.media.refresh_skipped', 'media_not_found', [
                'team_id' => $teamId,
                'message_id' => $messageId,
            ], calc: [
                'requested_count' => count($ids),
                'missing_count' => $missing,
            ]);
        }

        return $blocks;
    }
}
