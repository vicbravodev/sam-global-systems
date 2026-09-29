<?php

namespace App\Domains\Drivers\Exceptions;

use App\Support\SystemLog;
use RuntimeException;

/**
 * Thrown when a sync would claim a provider external id that already belongs
 * to another tenant's driver. `driver_external_references` is unique on
 * (provider_id, external_id) platform-wide, so the id cannot be shared: the
 * sync refuses BEFORE creating anything instead of writing over the owning
 * tenant's driver (the old behaviour) or leaving an orphan driver behind when
 * the reference insert hits the unique index. Callers that batch many drivers
 * catch this per-driver and continue with the rest.
 */
class DriverExternalReferenceConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $teamId,
        public readonly int $providerId,
        public readonly string $externalId,
    ) {
        parent::__construct(
            "External id {$externalId} of provider {$providerId} already belongs to another tenant's driver; "
                ."refusing to claim it for team {$teamId}.",
        );
    }

    /**
     * Log a skipped driver so a platform operator can see the collision
     * (it usually means two tenants connected the same provider account).
     */
    public function logSkipped(): void
    {
        SystemLog::skipped('drivers.sync.external_id_conflict', reason: 'owned_by_other_tenant', input: ['team_id' => $this->teamId, 'provider_id' => $this->providerId, 'external_id' => $this->externalId]);
    }
}
