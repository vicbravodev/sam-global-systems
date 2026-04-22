<?php

namespace App\Domains\Assets\Services;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;

class AssetSyncHandlerService implements AssetSyncHandler
{
    public function __construct(
        private SyncAssetFromIntegration $syncAssetAction,
    ) {}

    public function syncFromIntegration(int $teamId, int $integrationId, array $assetData): void
    {
        $this->syncAssetAction->execute($teamId, $integrationId, $assetData);
    }
}
