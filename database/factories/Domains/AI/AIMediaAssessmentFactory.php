<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIMediaAssessment>
 */
class AIMediaAssessmentFactory extends Factory
{
    protected $model = AIMediaAssessment::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => AIEventEvaluation::factory(),
            'event_media_context_id' => EventMediaContext::factory(),
            'media_type' => MediaType::Snapshot,
            'assessment_type' => MediaAssessmentType::VisualValidation,
            'result' => MediaAssessmentResult::ConfirmsEvent,
            'confidence_score' => 0.80,
            'extracted_signals_json' => null,
            'summary_text' => 'Baseline media assessment.',
            'latency_ms' => 50,
            'input_tokens' => 200,
            'output_tokens' => 60,
            'cost_estimate' => 0.0,
            'model_used' => 'null-media-agent:1.0',
            'assessed_at' => now(),
        ];
    }

    public function contradicts(): static
    {
        return $this->state(fn () => [
            'result' => MediaAssessmentResult::ContradictsEvent,
            'confidence_score' => 0.90,
            'summary_text' => 'Media contradicts the original signal.',
        ]);
    }

    public function inconclusive(): static
    {
        return $this->state(fn () => [
            'result' => MediaAssessmentResult::Inconclusive,
            'confidence_score' => 0.45,
            'summary_text' => 'Media is inconclusive.',
        ]);
    }
}
