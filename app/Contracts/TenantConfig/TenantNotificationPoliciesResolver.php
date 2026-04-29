<?php

namespace App\Contracts\TenantConfig;

use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Models\Team;

/**
 * Returns the tenant-wide notification policy: allowed channels, fallback rules,
 * tenant-level quiet hours, etc. Implemented by the TenantConfig domain reading
 * the `tenant_notification_policies` table.
 */
interface TenantNotificationPoliciesResolver
{
    public function resolve(Team $team): TenantNotificationPolicy;
}
