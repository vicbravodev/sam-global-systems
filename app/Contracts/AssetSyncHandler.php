<?php

namespace App\Contracts;

interface AssetSyncHandler
{
    /**
     * Sync asset data discovered from an integration provider.
     *
     * @param  array<string, mixed>  $assetData
     * @return 'created'|'updated'|'conflict'|null resultado del sync: `created`, `updated`, `conflict`, o null si la implementación no sincroniza
     */
    public function syncFromIntegration(int $teamId, int $integrationId, array $assetData): ?string;
}
