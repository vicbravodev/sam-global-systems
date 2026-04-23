<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIExplanation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIExplanation>
 */
class AIExplanationFactory extends Factory
{
    protected $model = AIExplanation::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => AIEventEvaluation::factory(),
            'summary' => 'Deterministic rules-only evaluation.',
            'reasoning_steps_json' => [],
            'key_factors_json' => [],
            'confidence_breakdown_json' => [],
            'evidence_used_json' => [],
        ];
    }
}
