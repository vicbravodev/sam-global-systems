<?php

namespace App\Contracts;

interface AssetSyncHandler
{
    /**
     * Sync asset data discovered from an integration provider.
     *
     * @param  array<string, mixed>  $assetData
     */
    public function syncFromIntegration(int $teamId, int $integrationId, array $assetData): void;
}
