<?php

namespace App\Domains\Assets\Services;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Exceptions\AssetLimitReachedException;

class AssetSyncHandlerService implements AssetSyncHandler
{
    public function __construct(
        private SyncAssetFromIntegration $syncAssetAction,
    ) {}

    public function syncFromIntegration(int $teamId, int $integrationId, array $assetData): void
    {
        try {
            $this->syncAssetAction->execute($teamId, $integrationId, $assetData);
        } catch (AssetLimitReachedException) {
            // At the asset cap: silently skip this net-new asset.
        }
    }
}
