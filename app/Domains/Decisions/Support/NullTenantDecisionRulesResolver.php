<?php

namespace App\Domains\Decisions\Support;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\Decisions\Data\TenantDecisionPolicy;

// SPEC-16-DEFERRED: replace with real resolver from TenantConfig domain when merged.
class NullTenantDecisionRulesResolver implements TenantDecisionRulesResolver
{
    public function resolve(int $teamId): TenantDecisionPolicy
    {
        return new TenantDecisionPolicy(
            humanReviewConfidenceThreshold: 0.5,
            automationLevel: 'semi',
            defaultRuleSetCode: 'default',
            allowAutomatedIncidents: true,
        );
    }
}
