<?php

namespace App\Domains\Integrations\Adapters;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Domains\Integrations\Contracts\NullProviderAdapter;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;

/**
 * Resolves the concrete {@see ProviderAdapter} for a given tenant integration
 * based on its provider code. This is the binding the consuming actions/jobs
 * receive, so a single container binding fans out to per-provider adapters.
 */
class ProviderAdapterManager implements MediaRetrievalAdapter, ProviderAdapter
{
    public function __construct(
        private SamsaraAdapter $samsara,
        private NullProviderAdapter $fallback,
    ) {}

    public function testConnection(TenantIntegration $integration): array
    {
        return $this->forIntegration($integration)->testConnection($integration);
    }

    public function sync(TenantIntegration $integration, string $type): array
    {
        return $this->forIntegration($integration)->sync($integration, $type);
    }

    public function fetchAssetLocations(TenantIntegration $integration): array
    {
        return $this->forIntegration($integration)->fetchAssetLocations($integration);
    }

    public function fetchLiveLocation(TenantIntegration $integration, string $externalAssetId): ?array
    {
        return $this->forIntegration($integration)->fetchLiveLocation($integration, $externalAssetId);
    }

    public function fetchSafetyEvents(TenantIntegration $integration, ?string $cursor = null, ?\DateTimeInterface $startTime = null): array
    {
        return $this->forIntegration($integration)->fetchSafetyEvents($integration, $cursor, $startTime);
    }

    public function requestMedia(
        TenantIntegration $integration,
        string $externalAssetId,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        array $inputs = [],
        string $mediaType = 'videoHighRes',
    ): ?string {
        $adapter = $this->forIntegration($integration);

        if (! $adapter instanceof MediaRetrievalAdapter) {
            return null;
        }

        return $adapter->requestMedia($integration, $externalAssetId, $startTime, $endTime, $inputs, $mediaType);
    }

    public function checkMedia(TenantIntegration $integration, string $retrievalId): array
    {
        $adapter = $this->forIntegration($integration);

        if (! $adapter instanceof MediaRetrievalAdapter) {
            return ['items' => []];
        }

        return $adapter->checkMedia($integration, $retrievalId);
    }

    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null): bool
    {
        // No integration context is available at signature-validation time.
        // Both Samsara and the null fallback use HMAC-SHA256, so delegating to
        // the Samsara adapter (which also accepts the raw-hex form) is safe.
        return $this->samsara->validateWebhookSignature($payload, $signature, $secret, $timestamp);
    }

    private function forIntegration(TenantIntegration $integration): ProviderAdapter
    {
        return match ($integration->provider?->code) {
            'samsara' => $this->samsara,
            default => $this->fallback,
        };
    }
}
