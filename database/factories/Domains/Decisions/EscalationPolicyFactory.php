<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\Decisions\Models\EscalationPolicy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationPolicy>
 */
class EscalationPolicyFactory extends Factory
{
    protected $model = EscalationPolicy::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'code' => 'esc-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'trigger_conditions_json' => null,
            'escalation_steps_json' => [
                ['after_seconds' => 60, 'notify' => 'supervisor'],
                ['after_seconds' => 300, 'notify' => 'manager'],
            ],
            'max_wait_seconds' => 600,
            'requires_acknowledgement' => false,
            'is_active' => true,
        ];
    }
}
