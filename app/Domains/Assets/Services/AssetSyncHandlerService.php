<?php

namespace App\Domains\Assets\Services;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Exceptions\AssetExternalReferenceConflictException;

class AssetSyncHandlerService implements AssetSyncHandler
{
    public function __construct(
        private SyncAssetFromIntegration $syncAssetAction,
    ) {}

    public function syncFromIntegration(int $teamId, int $integrationId, array $assetData): ?string
    {
        try {
            $asset = $this->syncAssetAction->execute($teamId, $integrationId, $assetData);

            return $asset->wasRecentlyCreated ? 'created' : 'updated';
        } catch (AssetExternalReferenceConflictException $e) {
            // The external id already belongs to another tenant's asset:
            // skip it (and log it) instead of writing over their data.
            $e->logSkipped();

            return 'conflict';
        }
    }
}
