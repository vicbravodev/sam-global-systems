<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Models\AIDecisionSignal;
use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIDecisionSignal>
 */
class AIDecisionSignalFactory extends Factory
{
    protected $model = AIDecisionSignal::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => AIEventEvaluation::factory(),
            'signal_code' => 'severity_level',
            'signal_value' => 'medium',
            'weight' => 0.50,
            'description' => null,
        ];
    }
}
