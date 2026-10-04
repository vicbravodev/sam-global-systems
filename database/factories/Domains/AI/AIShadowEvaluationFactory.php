<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIShadowEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * El hijo hereda tenant y evento de su evaluación (database/CLAUDE.md).
 *
 * @extends Factory<AIShadowEvaluation>
 */
class AIShadowEvaluationFactory extends Factory
{
    protected $model = AIShadowEvaluation::class;

    public function definition(): array
    {
        return [
            'ai_event_evaluation_id' => AIEventEvaluation::factory(),
            'team_id' => fn (array $attributes) => AIEventEvaluation::withoutGlobalScopes()->find($attributes['ai_event_evaluation_id'])?->team_id,
            'normalized_event_id' => fn (array $attributes) => AIEventEvaluation::withoutGlobalScopes()->find($attributes['ai_event_evaluation_id'])?->normalized_event_id,
            'model' => 'clef',
            'schema_version' => 1,
            'source' => AIShadowEvaluation::SOURCE_LIVE,
            'status' => AIShadowEvaluation::STATUS_SUCCESS,
            'classification' => 'real_event',
            'classification_probabilities_json' => ['real_event' => 0.8, 'false_positive' => 0.05, 'noise' => 0.05, 'duplicate' => 0.05, 'unclear' => 0.05],
            'risk_score' => 0.6,
            'needs_human_probability' => 0.7,
            'media_answers_json' => null,
            'images_sent' => 0,
            'input_tokens' => 2800,
            'output_tokens' => 0,
            'latency_ms' => 200,
            'cost_estimate' => 0.00067,
            'error_code' => null,
        ];
    }

    public function failed(string $errorCode = 'http_503'): static
    {
        return $this->state(fn () => [
            'status' => AIShadowEvaluation::STATUS_FAILED,
            'classification' => null,
            'classification_probabilities_json' => null,
            'risk_score' => null,
            'needs_human_probability' => null,
            'error_code' => $errorCode,
        ]);
    }
}
