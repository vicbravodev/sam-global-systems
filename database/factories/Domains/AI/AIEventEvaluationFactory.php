<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIEventEvaluation>
 */
class AIEventEvaluationFactory extends Factory
{
    protected $model = AIEventEvaluation::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'team_id' => Team::factory(),
            'evaluation_version' => 1,
            'evaluation_mode' => EvaluationMode::RulesOnly,
            'classification' => EventClassification::Unclear,
            'confidence_score' => 0.50,
            'risk_score' => 0.50,
            'priority_level' => EvaluationPriority::Normal,
            'is_real_event' => null,
            'requires_action' => false,
            'recommended_action' => null,
            'explanation_text' => null,
            'signals_json' => [],
            'evidence_summary_json' => [],
            'model_used' => 'rules-only-v1',
            'evaluated_at' => now(),
        ];
    }
}
