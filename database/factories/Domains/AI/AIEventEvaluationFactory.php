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
            'evaluation_mode' => EvaluationMode::AiText,
            'classification' => EventClassification::RealEvent,
            'confidence_score' => 0.85,
            'risk_score' => 0.60,
            'priority_level' => EvaluationPriority::Normal,
            'is_real_event' => true,
            'requires_action' => false,
            'recommended_action' => null,
            'explanation_text' => 'Baseline evaluation.',
            'signals_json' => [],
            'evidence_summary_json' => [],
            'model_used' => 'null-agent:1.0',
            'evaluated_at' => now(),
        ];
    }

    public function rulesOnly(): static
    {
        return $this->state(fn () => ['evaluation_mode' => EvaluationMode::RulesOnly]);
    }

    public function falsePositive(): static
    {
        return $this->state(fn () => [
            'classification' => EventClassification::FalsePositive,
            'is_real_event' => false,
            'confidence_score' => 0.92,
            'priority_level' => EvaluationPriority::Low,
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn () => [
            'priority_level' => EvaluationPriority::High,
            'requires_action' => true,
            'risk_score' => 0.85,
        ]);
    }
}
