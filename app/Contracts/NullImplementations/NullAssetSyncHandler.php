<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\AssetSyncHandler;

class NullAssetSyncHandler implements AssetSyncHandler
{
    public function syncFromIntegration(int $teamId, int $integrationId, array $assetData): void
    {
        // No-op: will be replaced when the Assets domain is implemented.
    }
}
