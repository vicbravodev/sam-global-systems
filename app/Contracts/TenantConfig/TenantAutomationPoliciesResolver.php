<?php

namespace App\Contracts\TenantConfig;

use App\Domains\Automation\Data\TenantAutomationPolicies;

/**
 * Returns the per-tenant automation policy bundle: maximum retries,
 * backoff schedule, confirmation TTL, and the automation level
 * (manual, semi, fully_auto). Implemented by the TenantConfig domain.
 */
interface TenantAutomationPoliciesResolver
{
    public function resolve(int $teamId): TenantAutomationPolicies;
}
