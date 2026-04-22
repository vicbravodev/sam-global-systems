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

    public function validateWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        return hash_equals(
            hash_hmac('sha256', $payload, $secret),
            $signature,
        );
    }
}
