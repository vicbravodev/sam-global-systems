<?php

namespace App\Contracts\TenantConfig;

interface TenantConfigResolver
{
    /**
     * Resolve a setting by key for a given team.
     *
     * Resolution precedence:
     *   1. Active `tenant_settings` row for the team and key.
     *   2. Plan / feature default (if any).
     *   3. The provided `$systemDefault`.
     */
    public function resolve(int $teamId, string $settingKey, mixed $systemDefault = null): mixed;

    /**
     * Invalidate any cached value for this `(team_id, setting_key)` tuple so
     * that the next `resolve()` call hits the database.
     */
    public function invalidate(int $teamId, string $settingKey): void;
}
