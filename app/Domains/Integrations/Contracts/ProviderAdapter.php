<?php

namespace App\Domains\Integrations\Contracts;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Integrations\Data\VehicleStatsPage;
use App\Domains\Integrations\Exceptions\ProviderCursorRejected;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
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
     * Read one page of the provider's vehicle stats feed.
     *
     * The feed is cursor-based and returns every update since the cursor —
     * several points per vehicle when it moved. Without a cursor it returns
     * the last known value of every vehicle and a cursor to follow from there.
     * Keep calling while `hasNextPage` is true; once it is false the provider
     * has nothing newer yet (Samsara asks for at least 5 s before the next
     * call).
     *
     * Values arrive already mapped to the domain's units (km/h, km, V, °C).
     * Providers without a stats feed return an empty page.
     *
     * @throws ProviderRateLimited
     * @throws ProviderUnauthorized
     * @throws ProviderCursorRejected when `$cursor` is expired or invalid
     * @throws ProviderUnavailable
     */
    public function fetchVehicleStatsFeed(TenantIntegration $integration, TelematicsFeed $feed, ?string $cursor = null): VehicleStatsPage;

    /**
     * Read one page of the vehicle stats recorded between two instants — the
     * backfill for a gap the feed can no longer replay (lost or expired
     * cursor). `$cursor` pages within the window. Same units and failures as
     * {@see fetchVehicleStatsFeed()}.
     *
     * @throws ProviderRateLimited
     * @throws ProviderUnauthorized
     * @throws ProviderUnavailable
     */
    public function fetchVehicleStatsHistory(
        TenantIntegration $integration,
        TelematicsFeed $feed,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $cursor = null,
    ): VehicleStatsPage;

    /**
     * Fetch the real connectivity of each asset's telematics device.
     *
     * Distinct from the stats feed: a GPS fix only moves when the
     * vehicle does (a parked unit reports roughly once an hour), so the age of
     * the last position says nothing about whether the device is online. This
     * is the provider's own "last connected" heartbeat, which the offline
     * watchdog uses as its liveness signal. One entry per asset; providers
     * without a connectivity feed return an empty list (and their assets are
     * then not watched for going offline).
     *
     * @return array<int, array{external_id: string, last_connected_at: string|null, health_status: string|null, serial: string|null, model: string|null}>
     */
    public function fetchDeviceConnectivity(TenantIntegration $integration): array;

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
     * poll together with the exact `start_time` string returned alongside it
     * (Samsara rejects a resumed page whose `startTime` differs from the one
     * that produced the cursor), or no cursor and a start time for a fresh
     * start. Implementations return the raw provider payload per event (the
     * ingestion pipeline stores it untransformed), the cursor and the pinned
     * `start_time` to persist for the next poll, and whether the page cap cut
     * the run short. Providers without a safety-event feed return no events
     * and echo the cursor back unchanged.
     *
     * A provider error is never reported as an empty result: it throws
     * {@see ProviderRequestFailedException}, or its subclass
     * {@see ProviderCursorRejectedException} when the cursor itself is no
     * longer usable and the feed must restart from a fresh start time.
     *
     * @return array{events: array<int, array<string, mixed>>, cursor: string|null, start_time: string|null, has_more: bool}
     *
     * @throws ProviderRequestFailedException
     */
    public function fetchSafetyEvents(TenantIntegration $integration, ?string $cursor = null, \DateTimeInterface|string|null $startTime = null): array;

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
