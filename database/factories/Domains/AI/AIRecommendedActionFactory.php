<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Enums\RecommendedActionType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIRecommendedAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIRecommendedAction>
 */
class AIRecommendedActionFactory extends Factory
{
    protected $model = AIRecommendedAction::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => AIEventEvaluation::factory(),
            'action_type' => RecommendedActionType::IgnoreEvent,
            'priority' => 5,
            'parameters_json' => [],
            'requires_confirmation' => false,
        ];
    }
}
