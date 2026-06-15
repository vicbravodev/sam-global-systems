<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Events\AssetDiscovered;
use App\Domains\Assets\Exceptions\AssetLimitReachedException;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Events\UsageLimitExceeded;

class SyncAssetFromIntegration
{
    public function __construct(
        private ResolveAssetFromExternalId $resolveAsset,
        private ResolveAssetLimit $resolveAssetLimit,
    ) {}

    /**
     * @param  array<string, mixed>  $assetData
     */
    public function execute(int $teamId, int $integrationId, array $assetData): Asset
    {
        $integration = TenantIntegration::withoutGlobalScopes()->findOrFail($integrationId);
        $providerId = $integration->provider_id;
        $externalId = $assetData['external_id'];

        $existingAsset = $this->resolveAsset->execute($providerId, $externalId);

        if ($existingAsset) {
            return $this->updateExistingAsset($existingAsset, $assetData, $providerId);
        }

        return $this->createNewAsset($teamId, $integrationId, $providerId, $assetData);
    }

    /**
     * @param  array<string, mixed>  $assetData
     */
    private function updateExistingAsset(Asset $asset, array $assetData, int $providerId): Asset
    {
        // Deliberately does NOT bump the asset's `last_seen_at`: this is an
        // inventory sync (the provider still lists the asset), not a real
        // signal. `last_seen_at` only moves with actual telemetry/location
        // (UpdateAssetLocationSnapshot), otherwise the whole fleet looks
        // "seen minutes ago" forever (C1-a) and offline detection goes blind.
        $asset->update(array_filter([
            'name' => $assetData['name'] ?? null,
            'code' => $assetData['code'] ?? null,
            'external_primary_id' => $assetData['external_id'],
            'metadata_json' => $assetData['metadata'] ?? null,
        ]));

        AssetExternalReference::where('provider_id', $providerId)
            ->where('external_id', $assetData['external_id'])
            ->update(['last_seen_at' => now()]);

        return $asset->fresh();
    }

    /**
     * @param  array<string, mixed>  $assetData
     */
    private function createNewAsset(int $teamId, int $integrationId, int $providerId, array $assetData): Asset
    {
        $this->assertWithinAssetLimit($teamId);

        $assetType = $this->resolveAssetType($assetData['asset_type_code'] ?? 'vehicle');

        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'asset_type_id' => $assetType->id,
            'provider_id' => $providerId,
            'source_integration_id' => $integrationId,
            'external_primary_id' => $assetData['external_id'],
            'name' => $assetData['name'] ?? 'Unknown Asset',
            'code' => $assetData['code'] ?? null,
            'metadata_json' => $assetData['metadata'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        AssetExternalReference::create([
            'asset_id' => $asset->id,
            'provider_id' => $providerId,
            'external_id' => $assetData['external_id'],
            'external_type' => $assetData['external_type'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        AssetDiscovered::dispatch(
            $teamId,
            $asset->id,
            $assetType->code,
            $asset->provider->code,
            $assetData['external_id'],
        );

        return $asset;
    }

    private function resolveAssetType(string $code): AssetType
    {
        return AssetType::where('code', $code)->firstOrFail();
    }

    /**
     * Enforce the tenant's monitored-asset cap before creating a new asset.
     * Updating already-known assets is always allowed; only net-new assets
     * beyond the cap are rejected.
     */
    private function assertWithinAssetLimit(int $teamId): void
    {
        $limit = $this->resolveAssetLimit->execute($teamId);

        if ($limit === null) {
            return;
        }

        $current = Asset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', '!=', AssetStatus::Inactive)
            ->count();

        if ($current >= $limit) {
            UsageLimitExceeded::dispatch($teamId, ResolveAssetLimit::METER_CODE, $current + 1, $limit);

            throw new AssetLimitReachedException($teamId, $limit, $current);
        }
    }
}
