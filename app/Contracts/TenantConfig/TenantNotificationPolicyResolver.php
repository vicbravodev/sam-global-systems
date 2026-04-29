<?php

namespace App\Contracts\TenantConfig;

use App\Domains\TenantConfig\Data\ResolvedNotificationPolicy;

interface TenantNotificationPolicyResolver
{
    /**
     * Resolve the active notification policy for the given tuple. When no
     * persisted policy matches, the implementation MUST return a default
     * policy with conservative `allowedChannels` (typically `['email']`).
     */
    public function resolve(int $teamId, ?string $notificationType = null, ?string $priority = null): ResolvedNotificationPolicy;
}
