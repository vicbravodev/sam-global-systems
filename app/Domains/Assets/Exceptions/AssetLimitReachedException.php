<?php

namespace App\Domains\Assets\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant has reached the asset cap of its plan (or per-tenant
 * override) and a sync would create an additional asset. Callers that batch
 * many assets should catch this per-asset and continue with the rest.
 */
class AssetLimitReachedException extends RuntimeException
{
    public function __construct(
        public readonly int $teamId,
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct(
            "Asset limit reached for team {$teamId}: {$current}/{$limit} monitored assets.",
        );
    }
}
