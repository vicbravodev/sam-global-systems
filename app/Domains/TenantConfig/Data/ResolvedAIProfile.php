<?php

namespace App\Domains\TenantConfig\Data;

use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;

/**
 * Spec 16's view of a tenant's AI behavior profile. Cross-domain consumers
 * (AI module, Decisions module) read this through the
 * `App\Contracts\TenantConfig\TenantAIProfileResolver` contract.
 */
final readonly class ResolvedAIProfile
{
    /**
     * @param  array<string, mixed>|null  $promptOverrides
     * @param  array<string, mixed>|null  $humanReviewPolicy
     */
    public function __construct(
        public int $teamId,
        public string $profileCode,
        public RiskTolerance $riskTolerance,
        public FalsePositiveTolerance $falsePositiveTolerance,
        public AutomationLevel $automationLevel,
        public MediaStrategy $mediaStrategy,
        public ?array $promptOverrides = null,
        public ?array $humanReviewPolicy = null,
        public bool $isPersisted = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'profile_code' => $this->profileCode,
            'risk_tolerance' => $this->riskTolerance->value,
            'false_positive_tolerance' => $this->falsePositiveTolerance->value,
            'automation_level' => $this->automationLevel->value,
            'media_strategy' => $this->mediaStrategy->value,
            'prompt_overrides' => $this->promptOverrides,
            'human_review_policy' => $this->humanReviewPolicy,
            'is_persisted' => $this->isPersisted,
        ];
    }
}
