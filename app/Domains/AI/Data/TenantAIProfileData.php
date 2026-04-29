<?php

namespace App\Domains\AI\Data;

/**
 * Shape of the per-tenant AI configuration consumed by the AI evaluation
 * pipeline. The `automation_level` field is sourced from spec 16's
 * `tenant_ai_profiles` table when a row exists for the team. Token and
 * call quota fields are still in-memory defaults pending a future quota
 * configuration in spec 16 (`SPEC-16-QUOTA-DEFERRED`).
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
