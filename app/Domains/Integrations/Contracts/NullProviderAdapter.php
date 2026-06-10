<?php

namespace App\Domains\Integrations\Contracts;

use App\Domains\Integrations\Models\TenantIntegration;

class NullProviderAdapter implements ProviderAdapter
{
    public function testConnection(TenantIntegration $integration): array
    {
        return ['success' => true, 'message' => 'Null adapter — no real connection tested.'];
    }

    public function sync(TenantIntegration $integration, string $type): array
    {
        return [
            'assets' => [],
            'drivers' => [],
            'events' => [],
            'records_processed' => 0,
        ];
    }

    public function fetchAssetLocations(TenantIntegration $integration): array
    {
        return [];
    }

    public function fetchLiveLocation(TenantIntegration $integration, string $externalAssetId): ?array
    {
        return null;
    }

    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null): bool
    {
        $provided = str_starts_with($signature, 'v1=') ? substr($signature, 3) : $signature;

        $message = $timestamp !== null && $timestamp !== ''
            ? 'v1:'.$timestamp.':'.$payload
            : $payload;

        return hash_equals(
            hash_hmac('sha256', $message, $secret),
            $provided,
        );
    }
}
