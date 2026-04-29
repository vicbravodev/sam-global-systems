<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\TenantConfig\TenantAnalyticsConfig;

/**
 * SPEC-16-DEFERRED stand-in: returns repo-wide defaults until the
 * TenantConfig domain ships and tenants can override these values.
 */
class NullTenantAnalyticsConfig implements TenantAnalyticsConfig
{
    public function reportRetentionDays(int $teamId): int
    {
        return 90;
    }

    public function enabledSnapshotTypes(int $teamId): array
    {
        return [
            'tenant_overview',
            'operational_summary',
            'ai_performance',
            'asset_risk_profile',
        ];
    }
}
