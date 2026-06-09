<?php

namespace App\Domains\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when a super-admin toggles or re-limits a tenant feature.
 */
class TenantFeatureChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $teamId,
        public string $featureKey,
        public bool $enabled,
    ) {}
}
