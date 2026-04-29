<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionOverride>
 */
class DecisionOverrideFactory extends Factory
{
    protected $model = DecisionOverride::class;

    public function definition(): array
    {
        return [
            'decision_id' => Decision::factory(),
            'overridden_by_user_id' => User::factory(),
            'previous_outcome' => DecisionOutcomeCode::LogOnly->value,
            'new_outcome' => DecisionOutcomeCode::Incident->value,
            'reason' => fake()->sentence(),
        ];
    }
}
