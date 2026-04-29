<?php

namespace App\Contracts\TenantConfig;

/**
 * SPEC-16-DEFERRED: Tenant-level analytics configuration.
 *
 * Implemented by the TenantConfig domain (spec 16) once it lands. Until then a
 * Null implementation returns repo-wide defaults (90-day retention, all default
 * snapshot types enabled).
 */
interface TenantAnalyticsConfig
{
    public function reportRetentionDays(int $teamId): int;

    /**
     * @return array<int, string>
     */
    public function enabledSnapshotTypes(int $teamId): array;
}
