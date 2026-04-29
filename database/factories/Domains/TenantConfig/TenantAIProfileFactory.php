<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantAIProfile>
 */
class TenantAIProfileFactory extends Factory
{
    protected $model = TenantAIProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'profile_code' => 'default',
            'name' => 'Default AI Profile',
            'description' => 'Plan-default AI behavior profile.',
            'prompt_overrides_json' => null,
            'risk_tolerance' => RiskTolerance::Medium,
            'false_positive_tolerance' => FalsePositiveTolerance::Medium,
            'automation_level' => AutomationLevel::Assisted,
            'media_strategy' => MediaStrategy::Preferred,
            'human_review_policy_json' => null,
            'is_active' => true,
        ];
    }
}
