<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Models\Team;

/**
 * SPEC-16-DEFERRED: Returns sensible defaults until the TenantConfig domain
 * implements the `notification_policies` table.
 */
class NullTenantNotificationPoliciesResolver implements TenantNotificationPoliciesResolver
{
    public function resolve(Team $team): TenantNotificationPolicy
    {
        return TenantNotificationPolicy::defaults();
    }
}
