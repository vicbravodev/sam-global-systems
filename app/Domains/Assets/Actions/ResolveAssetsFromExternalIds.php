<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;

/**
 * Batch counterpart of {@see ResolveAssetFromExternalId} for the fleet polls.
 *
 * A poll reports every vehicle of the org at once; resolving them one by one
 * costs two queries per vehicle per poll, which at a one-minute cadence is the
 * dominant cost of the whole job. This resolves the full batch in two queries
 * per chunk with the same isolation guarantee: `$teamId` is applied explicitly,
 * so an external id whose reference belongs to another tenant is simply absent
 * from the result.
 *
 * Only MONITORED assets resolve: a poll must never spend provider quota,
 * storage or alerts on a unit the tenant has not switched on (`pending`) or
 * has switched off (`excluded`). Those ids are omitted like unknown ones.
 */
class ResolveAssetsFromExternalIds
{
    /**
     * Keeps the bound parameters of each `whereIn` far below PostgreSQL's cap.
     */
    private const CHUNK_SIZE = 1000;

    /**
     * @param  iterable<int, string>  $externalIds
     * @return array<string, Asset> keyed by external id; unknown or foreign ids are omitted
     */
    public function execute(int $providerId, iterable $externalIds, int $teamId): array
    {
        $ids = [];

        foreach ($externalIds as $externalId) {
            if ($externalId !== '') {
                $ids[$externalId] = true;
            }
        }

        $resolved = [];

        foreach (array_chunk(array_map('strval', array_keys($ids)), self::CHUNK_SIZE) as $chunk) {
            $assetIdsByExternalId = AssetExternalReference::query()
                ->where('provider_id', $providerId)
                ->whereIn('external_id', $chunk)
                ->pluck('asset_id', 'external_id');

            if ($assetIdsByExternalId->isEmpty()) {
                continue;
            }

            $assets = Asset::query()
                ->where('team_id', $teamId)
                ->monitored()
                ->whereKey($assetIdsByExternalId->values()->unique()->all())
                ->get()
                ->keyBy('id');

            foreach ($assetIdsByExternalId as $externalId => $assetId) {
                if ($assets->has($assetId)) {
                    $resolved[(string) $externalId] = $assets->get($assetId);
                }
            }
        }

        return $resolved;
    }
}
