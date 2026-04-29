<?php

namespace App\Contracts\TenantConfig;

use App\Domains\TenantConfig\Data\ResolvedAIProfile;

interface TenantAIProfileResolver
{
    /**
     * Resolve the active AI profile for a team. When no persisted profile
     * exists the implementation MUST return a sensible default profile so
     * callers never have to handle nulls.
     */
    public function resolve(int $teamId): ResolvedAIProfile;
}
