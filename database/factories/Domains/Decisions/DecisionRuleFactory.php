<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionRule>
 */
class DecisionRuleFactory extends Factory
{
    protected $model = DecisionRule::class;

    public function definition(): array
    {
        return [
            'team_id' => null,
            'ruleset_id' => RuleSet::factory(),
            'code' => 'rule-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'scope' => RuleScope::Global,
            'priority' => 50,
            'conditions_json' => [
                'all' => [
                    ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                ],
            ],
            'outcome_override' => null,
            'escalation_policy_id' => null,
            'automation_action_id' => null,
            'stop_processing' => false,
            'is_active' => true,
        ];
    }
}
