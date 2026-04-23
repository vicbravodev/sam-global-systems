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
            'signal_code' => 'risk_score',
            'signal_value' => '0.60',
            'weight' => 0.5,
            'description' => 'Heuristic risk score',
        ];
    }
}
