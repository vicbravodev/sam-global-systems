<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantAnalyticsConfig;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ResolveTenantAnalyticsConfig implements TenantAnalyticsConfig
{
    /**
     * @var array<int, string>
     */
    private const DEFAULT_SNAPSHOT_TYPES = [
        'tenant_overview',
        'operational_summary',
        'ai_performance',
        'asset_risk_profile',
    ];

    private const DEFAULT_RETENTION_DAYS = 90;

    public function reportRetentionDays(int $teamId): int
    {
        return Cache::remember(
            CacheKeys::analyticsConfig($teamId).':retention',
            CacheKeys::TTL_SECONDS,
            fn (): int => self::DEFAULT_RETENTION_DAYS,
        );
    }

    public function enabledSnapshotTypes(int $teamId): array
    {
        return Cache::remember(
            CacheKeys::analyticsConfig($teamId).':snapshots',
            CacheKeys::TTL_SECONDS,
            fn (): array => self::DEFAULT_SNAPSHOT_TYPES,
        );
    }
}
