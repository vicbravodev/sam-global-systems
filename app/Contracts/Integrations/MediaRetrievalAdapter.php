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
     * @param  array<int, string>  $inputs  Provider camera inputs (e.g. dashcamRoadFacing).
     */
    public function requestMedia(
        TenantIntegration $integration,
        string $externalAssetId,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        array $inputs = [],
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
}
