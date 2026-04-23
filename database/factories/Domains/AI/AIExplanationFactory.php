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
            'summary' => 'Baseline deterministic explanation.',
            'reasoning_steps_json' => ['rules_pass', 'heuristic_pass', 'fusion_pass'],
            'key_factors_json' => ['severity' => 'normal'],
            'confidence_breakdown_json' => ['rules' => 0.5, 'ai_text' => 0.5],
            'evidence_used_json' => [],
        ];
    }
}
