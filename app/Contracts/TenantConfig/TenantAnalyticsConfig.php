<?php

namespace App\Contracts\TenantConfig;

/**
 * Tenant-level analytics configuration: report retention window and enabled
 * snapshot types. Implemented by the TenantConfig domain.
 */
interface TenantAnalyticsConfig
{
    public function reportRetentionDays(int $teamId): int;

    /**
     * @return array<int, string>
     */
    public function enabledSnapshotTypes(int $teamId): array;
}
