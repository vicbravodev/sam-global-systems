<?php

namespace App\Contracts\Integrations;

use App\Domains\Integrations\Models\TenantIntegration;
use DateTimeInterface;

interface MediaRetrievalAdapter
{
    /**
     * Ask the provider to retrieve camera media for a vehicle and time window
     * (e.g. Samsara `POST /cameras/media/retrieval`). Returns the provider's
     * retrieval id to poll with {@see checkMedia}, or null when the request
     * could not be placed (no token, provider error, no media capability).
     *
     * For still images the provider expects a single instant: pass the same
     * timestamp as both `$startTime` and `$endTime` with `$mediaType` "image".
     *
     * @param  array<int, string>  $inputs  Provider camera inputs (e.g. dashcamRoadFacing).
     * @param  string  $mediaType  Provider media type (e.g. videoHighRes, image).
     */
    public function requestMedia(
        TenantIntegration $integration,
        string $externalAssetId,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        array $inputs = [],
        string $mediaType = 'videoHighRes',
    ): ?string;

    /**
     * Poll a media retrieval previously placed with {@see requestMedia}.
     *
     * Each item reflects one camera input's clip: `status` is the provider's
     * value normalized to `pending|available|failed`, and `url` is the
     * downloadable (expiring) URL when available. An empty `items` list means
     * the provider could not be queried (transient) — callers keep polling
     * until their own expiry window closes.
     *
     * @return array{items: array<int, array{input: string|null, status: string, url: string|null}>}
     */
    public function checkMedia(TenantIntegration $integration, string $retrievalId): array;

    /**
     * List media the provider already holds for a vehicle and time window
     * (e.g. Samsara `GET /cameras/media`), without placing a new retrieval —
     * devices auto-upload footage for triggers like the panic button, so this
     * is the cheap, quota-free path to event evidence.
     *
     * Items use the same normalized shape as {@see checkMedia} plus the media
     * type, trigger and capture instant. Camera inputs are normalized to the
     * retrieval vocabulary (`dashcamRoadFacing`/`dashcamDriverFacing`). An
     * empty list means nothing is uploaded for the window (or the provider
     * could not be queried).
     *
     * @param  array<int, string>  $triggerReasons  Provider trigger filters (e.g. panicButton, safetyEvent); empty = all.
     * @return array{items: array<int, array{input: string|null, status: string, url: string|null, media_type: string|null, trigger_reason: string|null, start_time: string|null}>}
     */
    public function listUploadedMedia(
        TenantIntegration $integration,
        string $externalAssetId,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        array $triggerReasons = [],
    ): array;
}
