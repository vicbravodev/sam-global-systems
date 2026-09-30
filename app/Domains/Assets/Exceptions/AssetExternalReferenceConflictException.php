<?php

namespace App\Domains\Assets\Exceptions;

use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
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

    /**
     * Log a skipped asset so a platform operator can see the collision (it
     * usually means two tenants connected the same provider account). The
     * `team_id` is the tenant that ASKED for the id; the owner is never
     * looked up nor logged.
     */
    public function logSkipped(): void
    {
        TenantContext::for($this->teamId, fn () => SystemLog::skipped('assets.sync.external_id_conflict', reason: 'owned_by_other_tenant', input: [
            'team_id' => $this->teamId,
            'provider_id' => $this->providerId,
            'external_id' => LoggableCode::guard($this->externalId),
        ]));
    }
}
