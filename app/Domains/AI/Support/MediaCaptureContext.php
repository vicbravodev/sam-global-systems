<?php

namespace App\Domains\AI\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Derives, from a media item's metadata, which camera captured it and how far
 * from the event instant. The vision model needs both: a road-facing frame
 * can never prove a passenger, and a still taken 20 minutes after a panic
 * shows context, not the event.
 *
 * The Samsara adapter already normalizes `dashcamForwardFacing` /
 * `dashcamInwardFacing` to `dashcamRoadFacing` / `dashcamDriverFacing`; the
 * legacy safety-event download keys (`downloadForwardVideoUrl`,
 * `downloadInwardVideoUrl`) and the stream's `cameraRole` are mapped here.
 */
final class MediaCaptureContext
{
    public const string ROAD = 'road';

    public const string DRIVER = 'driver';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function cameraSide(array $metadata): ?string
    {
        $candidates = [
            $metadata['camera_side'] ?? null,
            $metadata['input'] ?? null,
            $metadata['camera_role'] ?? null,
            $metadata['source_url_key'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $value = strtolower($candidate);

            if (str_contains($value, 'road') || str_contains($value, 'forward') || in_array($value, ['front', 'outward'], true)) {
                return self::ROAD;
            }

            if (str_contains($value, 'driver') || str_contains($value, 'inward') || str_contains($value, 'cabin')) {
                return self::DRIVER;
            }
        }

        return null;
    }

    /**
     * Seconds between capture and event (negative = captured before). Uses the
     * still retrieval offset when present, else an explicit capture timestamp.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function captureOffsetSeconds(array $metadata, ?CarbonInterface $eventOccurredAt): ?int
    {
        // Still retrievals and frames extracted from a clip (`video_frame`)
        // carry their offset from the event instant.
        if (isset($metadata['offset_seconds']) && is_numeric($metadata['offset_seconds'])) {
            return (int) round((float) $metadata['offset_seconds']);
        }

        $capturedAt = $metadata['start_time'] ?? $metadata['captured_at'] ?? null;

        if ($eventOccurredAt === null || ! is_string($capturedAt) || $capturedAt === '') {
            return null;
        }

        try {
            return (int) round(Carbon::parse($capturedAt)->getTimestamp() - $eventOccurredAt->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }
}
