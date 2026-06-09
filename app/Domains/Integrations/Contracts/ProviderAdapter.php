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
     * Validate a webhook signature against the provider's algorithm.
     *
     * @param  string  $payload  Exact raw request body bytes.
     * @param  string  $signature  Signature header value (may be prefixed, e.g. "v1=").
     * @param  string  $secret  The endpoint's shared secret.
     * @param  string|null  $timestamp  Optional signature timestamp header used in the signed message.
     */
    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null): bool;
}
