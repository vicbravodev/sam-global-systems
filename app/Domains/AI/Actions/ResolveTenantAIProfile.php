<?php

namespace App\Domains\AI\Actions;

use App\Contracts\TenantConfig\TenantAIProfileResolver;
use App\Domains\AI\Data\TenantAIProfileData;

/**
 * Bridges spec 16 (`tenant_ai_profiles`) into the AI evaluation pipeline.
 *
 * When a persisted profile exists for the team, its `automation_level` is
 * adopted; otherwise, conservative in-memory defaults are returned. Token
 * and call quotas come from `config('ai.quota')` (env-overridable:
 * `AI_QUOTA_MONTHLY_TOKEN_LIMIT`, `AI_QUOTA_DAILY_CALL_LIMIT`).
 */
class ResolveTenantAIProfile
{
    public const DEFAULT_AUTOMATION_LEVEL = 'semi';

    public const DEFAULT_MONTHLY_TOKEN_LIMIT = 5_000_000;

    public const DEFAULT_DAILY_CALL_LIMIT = 2_000;

    public const DEFAULT_PREFERRED_MODEL = 'null-agent:1.0';

    public function __construct(
        private readonly TenantAIProfileResolver $tenantConfigResolver,
    ) {}

    public function execute(int $teamId): TenantAIProfileData
    {
        $resolved = $this->tenantConfigResolver->resolve($teamId);

        $automationLevel = $resolved->isPersisted
            ? $resolved->automationLevel->value
            : self::DEFAULT_AUTOMATION_LEVEL;

        return new TenantAIProfileData(
            teamId: $teamId,
            automationLevel: $automationLevel,
            monthlyTokenLimit: max(0, (int) config('ai.quota.monthly_token_limit', self::DEFAULT_MONTHLY_TOKEN_LIMIT)),
            dailyCallLimit: max(0, (int) config('ai.quota.daily_call_limit', self::DEFAULT_DAILY_CALL_LIMIT)),
            preferredModel: self::DEFAULT_PREFERRED_MODEL,
        );
    }
}
