<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\Decisions\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleSet>
 */
class RuleSetFactory extends Factory
{
    protected $model = RuleSet::class;

    public function definition(): array
    {
        return [
            'team_id' => null,
            'code' => 'ruleset-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'version' => 1,
            'is_default' => false,
            'is_active' => true,
            'applies_to_json' => null,
        ];
    }

    public function global(): static
    {
        return $this->state(fn () => [
            'team_id' => null,
            'is_default' => true,
        ]);
    }
}
