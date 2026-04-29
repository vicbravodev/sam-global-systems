<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Data\TenantAutomationPolicies;

/**
 * SPEC-16-DEFERRED: deterministic stand-in until the TenantConfig domain ships.
 */
class NullTenantAutomationPoliciesResolver implements TenantAutomationPoliciesResolver
{
    public function resolve(int $teamId): TenantAutomationPolicies
    {
        return TenantAutomationPolicies::defaults();
    }
}
