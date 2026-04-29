<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Data\TenantAutomationPolicies;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ResolveTenantAutomationPolicies implements TenantAutomationPoliciesResolver
{
    public function resolve(int $teamId): TenantAutomationPolicies
    {
        return Cache::remember(
            CacheKeys::automationPolicies($teamId),
            CacheKeys::TTL_SECONDS,
            fn (): TenantAutomationPolicies => TenantAutomationPolicies::defaults(),
        );
    }
}
