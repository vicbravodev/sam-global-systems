<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;

class ResolveAssetFromExternalId
{
    /**
     * Resolve which asset of `$teamId` a provider-side external id points to.
     *
     * `asset_external_references` is unique on (provider_id, external_id)
     * across the whole platform, so one external id maps to exactly one asset
     * — of any tenant. `$teamId` is required (not optional) so isolation never
     * rests on how an external system allocates its identifiers: a reference
     * owned by another team resolves to null, and callers skip it.
     *
     * Global scopes are bypassed because callers are queued jobs with no
     * authenticated team context; the tenant filter is applied explicitly.
     */
    public function execute(int $providerId, string $externalId, int $teamId): ?Asset
    {
        $reference = AssetExternalReference::where('provider_id', $providerId)
            ->where('external_id', $externalId)
            ->first();

        if (! $reference) {
            return null;
        }

        return Asset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($reference->asset_id);
    }
}
