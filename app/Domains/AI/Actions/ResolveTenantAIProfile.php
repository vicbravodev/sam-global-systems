<?php

namespace App\Domains\AI\Actions;

use App\Contracts\TenantConfig\TenantAIProfileResolver;
use App\Domains\AI\Data\TenantAIProfileData;

/**
 * Bridges spec 16 (`tenant_ai_profiles`) into the AI evaluation pipeline.
 *
 * When a persisted profile exists for the team, its `automation_level` is
 * adopted; otherwise, conservative in-memory defaults are returned. Token
 * and call quota fields are kept in-memory pending a dedicated quota
 * configuration in spec 16.
 */
class ResolveTenantAIProfile
{
    public const DEFAULT_AUTOMATION_LEVEL = 'semi';

    public const DEFAULT_MONTHLY_TOKEN_LIMIT = 1_000_000;

    public const DEFAULT_DAILY_CALL_LIMIT = 10_000;

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
            monthlyTokenLimit: self::DEFAULT_MONTHLY_TOKEN_LIMIT,
            dailyCallLimit: self::DEFAULT_DAILY_CALL_LIMIT,
            preferredModel: self::DEFAULT_PREFERRED_MODEL,
        );
    }
}
