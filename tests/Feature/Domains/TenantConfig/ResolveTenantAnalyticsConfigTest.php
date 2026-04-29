<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantAnalyticsConfig;
use App\Domains\TenantConfig\Actions\ResolveTenantAnalyticsConfig;
use Tests\TestCase;

class ResolveTenantAnalyticsConfigTest extends TestCase
{
    public function test_contract_resolves_to_tenantconfig_action(): void
    {
        $this->assertInstanceOf(
            ResolveTenantAnalyticsConfig::class,
            app(TenantAnalyticsConfig::class),
        );
    }

    public function test_resolve_returns_default_retention(): void
    {
        $this->assertSame(90, app(TenantAnalyticsConfig::class)->reportRetentionDays(11));
    }

    public function test_resolve_returns_default_snapshot_types(): void
    {
        $types = app(TenantAnalyticsConfig::class)->enabledSnapshotTypes(11);

        $this->assertSame([
            'tenant_overview',
            'operational_summary',
            'ai_performance',
            'asset_risk_profile',
        ], $types);
    }
}
