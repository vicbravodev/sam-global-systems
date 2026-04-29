<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\Decisions\Data\TenantDecisionPolicy;
use App\Domains\TenantConfig\Actions\ResolveTenantDecisionRules;
use Tests\TestCase;

class ResolveTenantDecisionRulesTest extends TestCase
{
    public function test_contract_resolves_to_tenantconfig_action(): void
    {
        $this->assertInstanceOf(
            ResolveTenantDecisionRules::class,
            app(TenantDecisionRulesResolver::class),
        );
    }

    public function test_resolve_returns_default_policy(): void
    {
        $policy = app(TenantDecisionRulesResolver::class)->resolve(42);

        $this->assertInstanceOf(TenantDecisionPolicy::class, $policy);
        $this->assertSame(0.5, $policy->humanReviewConfidenceThreshold);
        $this->assertSame('semi', $policy->automationLevel);
        $this->assertSame('default', $policy->defaultRuleSetCode);
        $this->assertTrue($policy->allowAutomatedIncidents);
    }
}
