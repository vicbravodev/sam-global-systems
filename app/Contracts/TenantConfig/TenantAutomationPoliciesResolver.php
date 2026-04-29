<?php

namespace App\Contracts\TenantConfig;

use App\Domains\Automation\Data\TenantAutomationPolicies;

/**
 * SPEC-16-DEFERRED: Final implementation lands with the TenantConfig domain.
 *
 * Returns the per-tenant automation policy bundle: maximum retries,
 * backoff schedule, confirmation TTL, and the automation level
 * (manual, semi, fully_auto). Until spec 16 lands, the bound implementation
 * (`NullTenantAutomationPoliciesResolver`) returns sane defaults.
 */
interface TenantAutomationPoliciesResolver
{
    public function resolve(int $teamId): TenantAutomationPolicies;
}
