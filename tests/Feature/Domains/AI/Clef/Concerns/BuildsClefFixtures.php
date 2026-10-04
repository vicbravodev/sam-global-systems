<?php

namespace Tests\Feature\Domains\AI\Clef\Concerns;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;

trait BuildsClefFixtures
{
    /**
     * Evaluación oficial + inference log con snapshot, todo en el mismo tenant.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $evaluation
     */
    protected function makeEvaluation(Team $team, array $snapshot = [], array $evaluation = []): AIEventEvaluation
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $model = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            ...$evaluation,
        ]);

        AIInferenceLog::factory()->create([
            'evaluation_id' => $model->id,
            'input_snapshot_json' => $snapshot !== [] ? $snapshot : [
                'normalized_event_id' => $event->id,
                'normalized_event' => ['type_code' => 'harsh_brake', 'type_name' => 'Frenado brusco'],
                'telemetry' => ['speed_kph' => 62],
                'recent_history' => [],
                'media_assessments' => [],
            ],
            'input_tokens' => 2800,
        ]);

        return $model;
    }

    /**
     * Evaluación de un evento de un tipo concreto (p. ej. uno que ya no pasa
     * por IA), en el mismo tenant.
     */
    protected function makeEvaluationOfType(Team $team, string $typeCode): AIEventEvaluation
    {
        $evaluation = $this->makeEvaluation($team);
        $type = EventType::query()->firstOrCreate(['code' => $typeCode], EventType::factory()->make(['code' => $typeCode])->getAttributes());
        NormalizedEvent::withoutGlobalScopes()->whereKey($evaluation->normalized_event_id)->update(['event_type_id' => $type->id]);

        return $evaluation;
    }

    /**
     * Cuerpo de Workers AI (sobre `result`) para las preguntas sin imágenes.
     *
     * @param  array<string, mixed>  $overrides  se mezcla sobre `answers`
     * @return array<string, mixed>
     */
    protected function clefResponse(string $classification, array $overrides = [], int $inputTokens = 2900): array
    {
        $options = ['real_event', 'false_positive', 'noise', 'duplicate', 'unclear'];
        $probabilities = array_fill_keys($options, 0.05);
        $probabilities[$classification] = 0.8;

        return [
            'success' => true,
            'errors' => [],
            'result' => [
                'model' => 'clef',
                'answers' => [
                    'classification' => ['type' => 'choice', 'choice' => $classification, 'probabilities' => $probabilities, 'confidence' => 0.8],
                    'severity' => ['type' => 'score', 'score' => 3.0, 'legend' => [], 'probabilities' => ['0' => 0.0, '1' => 0.1, '2' => 0.1, '3' => 0.5, '4' => 0.3], 'confidence' => 0.5],
                    'needs_human_now' => ['type' => 'noul', 'noul' => 0.72],
                    ...$overrides,
                ],
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 0],
            ],
        ];
    }
}
