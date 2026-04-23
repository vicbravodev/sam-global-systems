<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Data\TenantAIProfileData;

/**
 * SPEC-16-DEFERRED: returns an in-memory default profile until the
 * TenantConfig domain (spec 16) provides a persisted `tenant_ai_profiles`
 * table. When spec 16 lands, this action will query that table.
 */
class ResolveTenantAIProfile
{
    public const DEFAULT_AUTOMATION_LEVEL = 'semi';

    public const DEFAULT_MONTHLY_TOKEN_LIMIT = 1_000_000;

    public const DEFAULT_DAILY_CALL_LIMIT = 10_000;

    public const DEFAULT_PREFERRED_MODEL = 'null-agent:1.0';

    public function execute(int $teamId): TenantAIProfileData
    {
        return new TenantAIProfileData(
            teamId: $teamId,
            automationLevel: self::DEFAULT_AUTOMATION_LEVEL,
            monthlyTokenLimit: self::DEFAULT_MONTHLY_TOKEN_LIMIT,
            dailyCallLimit: self::DEFAULT_DAILY_CALL_LIMIT,
            preferredModel: self::DEFAULT_PREFERRED_MODEL,
        );
    }
}
