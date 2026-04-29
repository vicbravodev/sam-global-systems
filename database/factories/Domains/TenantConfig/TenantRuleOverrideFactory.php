<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantRuleOverride>
 */
class TenantRuleOverrideFactory extends Factory
{
    protected $model = TenantRuleOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'base_rule_code' => 'speed_violation',
            'override_type' => RuleOverrideType::ChangeThreshold,
            'override_config_json' => ['threshold_mph' => 80],
            'reason' => null,
            'is_active' => true,
        ];
    }
}
