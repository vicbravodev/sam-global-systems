<?php

namespace App\Domains\Integrations\Contracts;

use App\Domains\Integrations\Models\TenantIntegration;

interface ProviderAdapter
{
    /**
     * Test the connection to the external provider.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(TenantIntegration $integration): array;

    /**
     * Execute a full or incremental sync and return discovered records.
     *
     * @return array{assets: array<int, array<string, mixed>>, drivers: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>, records_processed: int}
     */
    public function sync(TenantIntegration $integration, string $type): array;

    /**
     * Fetch the latest known location for each asset tracked by the provider.
     *
     * Returned independently from {@see sync()} because positions refresh far
     * more frequently than the asset/driver catalog and are polled on their own
     * cadence to keep the fleet map current.
     *
     * @return array<int, array{external_id: string, latitude: float, longitude: float, speed?: float|null, heading?: int|null, formatted_location?: string|null, recorded_at?: string|null}>
     */
    public function fetchAssetLocations(TenantIntegration $integration): array;

    /**
     * Fetch the current position of a single asset directly from the provider.
     *
     * Used for on-demand refreshes (e.g. a critical event whose latest known
     * position is stale), so implementations should use a short timeout and
     * return `null` on any failure — callers always degrade to the latest
     * stored location instead of failing their pipeline.
     *
     * @return array{external_id: string, latitude: float, longitude: float, speed?: float|null, heading?: int|null, formatted_location?: string|null, recorded_at?: string|null}|null
     */
    public function fetchLiveLocation(TenantIntegration $integration, string $externalAssetId): ?array;

    /**
     * Fetch safety events from the provider's streaming feed.
     *
     * The feed is cursor-based: pass the cursor persisted from the previous
     * poll to resume where it left off, or a start time for the first poll.
     * Implementations return the raw provider payload per event (the ingestion
     * pipeline stores it untransformed) plus the cursor to persist for the
     * next poll. Providers without a safety-event feed return no events and
     * echo the cursor back unchanged.
     *
     * @return array{events: array<int, array<string, mixed>>, cursor: string|null}
     */
    public function fetchSafetyEvents(TenantIntegration $integration, ?string $cursor = null, ?\DateTimeInterface $startTime = null): array;

    /**
     * Validate a webhook signature against the provider's algorithm.
     *
     * @param  string  $payload  Exact raw request body bytes.
     * @param  string  $signature  Signature header value (may be prefixed, e.g. "v1=").
     * @param  string  $secret  The endpoint's shared secret.
     * @param  string|null  $timestamp  Optional signature timestamp header used in the signed message.
     */
    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null): bool;
}
