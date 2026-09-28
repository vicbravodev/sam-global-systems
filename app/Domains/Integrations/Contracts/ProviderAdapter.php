<?php

namespace App\Domains\Integrations\Contracts;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
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
     * Fetch the latest onboard-diagnostic readings for each asset.
     *
     * Separate from {@see fetchAssetLocations()} because these stats change on
     * their own (much slower) schedules — fuel by the percent, odometer by the
     * kilometre — and are polled on a slower cadence than positions.
     *
     * Implementations return one entry per (asset, reading) pair with values
     * already normalized to the unit the domain stores: km, volts, °C. Stats a
     * vehicle does not report are omitted rather than returned as null.
     *
     * @return array<int, array{external_id: string, type: TelemetryType, value: float|string, unit: string|null, recorded_at: string|null}>
     */
    public function fetchAssetTelemetry(TenantIntegration $integration): array;

    /**
     * Fetch the real connectivity of each asset's telematics device.
     *
     * Distinct from {@see fetchAssetLocations()}: a GPS fix only moves when the
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
