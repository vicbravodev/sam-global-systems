<?php

namespace App\Infrastructure\AI\Clef;

use App\Domains\AI\Data\ClefDecision;

/**
 * Una evaluación de un evento con un modelo Clef: arma estado y preguntas,
 * llama y traduce las respuestas a una ClefDecision.
 */
class ClefEventDecider
{
    public function __construct(
        private readonly ClefClient $client,
        private readonly ClefStateBuilder $stateBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot  AIInputContext::toArray() guardado en ai_inference_logs
     * @param  list<array{content_type: string, base64: string}>  $images
     */
    public function decide(string $model, array $snapshot, array $images): ClefDecision
    {
        $withImages = $images !== [];

        $response = $this->client->run($model, $this->stateBuilder->fromSnapshot($snapshot), ClefQuestionSchema::for($withImages), $images);
        $answers = $response->answers;

        $mediaAnswers = $withImages
            ? array_intersect_key($answers, array_flip(['media_result', 'persons_visible', ...ClefQuestionSchema::MEDIA_SIGNALS]))
            : null;

        $prices = (array) config('ai.clef.pricing_per_million_input', []);
        $pricePerMillion = (float) ($prices[$model] ?? 0.0);

        return new ClefDecision(
            model: $model,
            classification: (string) $answers['classification']['choice'],
            classificationProbabilities: array_map(floatval(...), (array) $answers['classification']['probabilities']),
            riskScore: round(max(0.0, min(1.0, (float) $answers['severity']['score'] / (ClefQuestionSchema::SEVERITY_LEVELS - 1))), 2),
            needsHumanProbability: round((float) $answers['needs_human_now']['noul'], 3),
            mediaAnswers: $mediaAnswers,
            imagesSent: count($images),
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            latencyMs: $response->latencyMs,
            costEstimate: round($response->inputTokens * $pricePerMillion / 1_000_000, 5),
        );
    }
}
