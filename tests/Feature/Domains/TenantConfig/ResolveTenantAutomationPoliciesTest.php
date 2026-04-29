<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Data\TenantAutomationPolicies;
use App\Domains\TenantConfig\Actions\ResolveTenantAutomationPolicies;
use Tests\TestCase;

class ResolveTenantAutomationPoliciesTest extends TestCase
{
    public function test_contract_resolves_to_tenantconfig_action(): void
    {
        $this->assertInstanceOf(
            ResolveTenantAutomationPolicies::class,
            app(TenantAutomationPoliciesResolver::class),
        );
    }

    public function test_resolve_returns_default_policies(): void
    {
        $policies = app(TenantAutomationPoliciesResolver::class)->resolve(7);

        $this->assertInstanceOf(TenantAutomationPolicies::class, $policies);
        $this->assertSame('semi', $policies->automationLevel);
        $this->assertSame(3, $policies->maxRetries);
        $this->assertSame([10, 60, 300], $policies->retryBackoffSeconds);
        $this->assertSame(1800, $policies->confirmationTtlSeconds);
    }
}
