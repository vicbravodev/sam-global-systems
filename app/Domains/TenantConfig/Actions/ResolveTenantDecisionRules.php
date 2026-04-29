<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\Decisions\Data\TenantDecisionPolicy;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ResolveTenantDecisionRules implements TenantDecisionRulesResolver
{
    public function resolve(int $teamId): TenantDecisionPolicy
    {
        return Cache::remember(
            CacheKeys::decisionRules($teamId),
            CacheKeys::TTL_SECONDS,
            fn (): TenantDecisionPolicy => new TenantDecisionPolicy,
        );
    }
}
