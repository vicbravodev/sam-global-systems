<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;

class ResolveAssetFromExternalId
{
    public function execute(int $providerId, string $externalId): ?Asset
    {
        $reference = AssetExternalReference::where('provider_id', $providerId)
            ->where('external_id', $externalId)
            ->first();

        if (! $reference) {
            return null;
        }

        return Asset::withoutGlobalScopes()->find($reference->asset_id);
    }
}
