<?php

namespace App\Domains\Assets\Exceptions;

use RuntimeException;

/**
 * Thrown when a sync would claim a provider external id that already belongs
 * to another tenant's asset. `asset_external_references` is unique on
 * (provider_id, external_id) platform-wide, so the id cannot be shared: the
 * sync refuses instead of writing over the owning tenant's asset. Callers that
 * batch many assets should catch this per-asset and continue with the rest.
 */
class AssetExternalReferenceConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $teamId,
        public readonly int $providerId,
        public readonly string $externalId,
    ) {
        parent::__construct(
            "External id {$externalId} of provider {$providerId} already belongs to another tenant; "
                ."refusing to claim it for team {$teamId}.",
        );
    }
}
