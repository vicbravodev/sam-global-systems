<?php

namespace App\Domains\Integrations\Contracts;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Integrations\Data\VehicleStatsPage;
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

    public function fetchVehicleStatsFeed(TenantIntegration $integration, TelematicsFeed $feed, ?string $cursor = null): VehicleStatsPage
    {
        return VehicleStatsPage::empty($cursor);
    }

    public function fetchVehicleStatsHistory(
        TenantIntegration $integration,
        TelematicsFeed $feed,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $cursor = null,
    ): VehicleStatsPage {
        return VehicleStatsPage::empty();
    }

    public function fetchDeviceConnectivity(TenantIntegration $integration): array
    {
        return [];
    }

    public function fetchLiveLocation(TenantIntegration $integration, string $externalAssetId): ?array
    {
        return null;
    }

    public function fetchSafetyEvents(TenantIntegration $integration, ?string $cursor = null, \DateTimeInterface|string|null $startTime = null): array
    {
        return [
            'events' => [],
            'cursor' => $cursor,
            'start_time' => is_string($startTime) ? $startTime : null,
            'has_more' => false,
        ];
    }

    public function fetchAlertConfigurations(TenantIntegration $integration): array
    {
        return [];
    }

    public function fetchAlertIncidents(TenantIntegration $integration, array $configurationIds, string $startTime, ?string $cursor = null): array
    {
        return ['incidents' => [], 'cursor' => $cursor, 'has_more' => false];
    }

    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null, ?\DateTimeInterface $receivedAt = null): bool
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
