<?php

namespace App\Domains\TenantConfig\Support;

/**
 * Centralizes cache key strings used across TenantConfig actions so that
 * `UpdateTenantSetting` and the resolvers stay in sync. Tenant-scoped
 * caches use a `tenant_config:{teamId}` prefix; per-key caches additionally
 * encode the lookup key so individual invalidations are cheap.
 */
final class CacheKeys
{
    public const TTL_SECONDS = 300;

    public static function setting(int $teamId, string $settingKey): string
    {
        return "tenant_config:{$teamId}:setting:{$settingKey}";
    }

    public static function aiProfile(int $teamId): string
    {
        return "tenant_config:{$teamId}:ai_profile";
    }

    public static function notificationPolicy(int $teamId, ?string $notificationType, ?string $priority): string
    {
        $type = $notificationType ?? '__any__';
        $prio = $priority ?? '__any__';

        return "tenant_config:{$teamId}:notification:{$type}:{$prio}";
    }

    public static function schedule(int $teamId): string
    {
        return "tenant_config:{$teamId}:schedule";
    }

    public static function ruleOverride(int $teamId, string $ruleCode): string
    {
        return "tenant_config:{$teamId}:rule_override:{$ruleCode}";
    }

    public static function decisionRules(int $teamId): string
    {
        return "tenant_config:{$teamId}:decision_rules";
    }

    public static function automationPolicies(int $teamId): string
    {
        return "tenant_config:{$teamId}:automation_policies";
    }

    public static function notificationPoliciesGlobal(int $teamId): string
    {
        return "tenant_config:{$teamId}:notification_policies:global";
    }

    public static function analyticsConfig(int $teamId): string
    {
        return "tenant_config:{$teamId}:analytics_config";
    }
}
