<?php

namespace App\Contracts\TenantConfig;

use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Models\Team;

/**
 * SPEC-16-DEFERRED: Bridges spec 13 (Notifications) with spec 16 (Tenant Config).
 * Returns the tenant-wide notification policy: allowed channels, fallback rules,
 * tenant-level quiet hours, etc. Implemented by a Null resolver with sensible defaults
 * until the TenantConfig domain ships its `notification_policies` table.
 */
interface TenantNotificationPoliciesResolver
{
    public function resolve(Team $team): TenantNotificationPolicy;
}
