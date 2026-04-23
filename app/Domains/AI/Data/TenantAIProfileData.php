<?php

namespace App\Domains\AI\Data;

/**
 * SPEC-16-DEFERRED: shape of the per-tenant AI configuration until spec 16
 * (TenantConfig) provides a persisted `tenant_ai_profiles` table.
 */
final readonly class TenantAIProfileData
{
    public function __construct(
        public int $teamId,
        public string $automationLevel,
        public int $monthlyTokenLimit,
        public int $dailyCallLimit,
        public string $preferredModel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'automation_level' => $this->automationLevel,
            'monthly_token_limit' => $this->monthlyTokenLimit,
            'daily_call_limit' => $this->dailyCallLimit,
            'preferred_model' => $this->preferredModel,
        ];
    }
}
