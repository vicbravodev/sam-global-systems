<?php

namespace App\Infrastructure\AI\Clef;

use App\Domains\AI\Data\ClefDecision;
use App\Support\SystemLog;

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
        $state = $this->stateBuilder->fromSnapshot($snapshot);

        // Cloudflare estima los tokens de cada imagen por su contenido: un
        // evento con imágenes densas puede pasar la ventana de 65K aunque
        // pese poco (413). Se reintenta con la mitad hasta quedar en texto.
        while (true) {
            try {
                $response = $this->client->run($model, $state, ClefQuestionSchema::for($images !== []), $images);

                break;
            } catch (ClefRequestFailedException $e) {
                if ($e->reason !== 'http_413' || $images === []) {
                    throw $e;
                }

                $before = count($images);
                $images = array_slice($images, 0, intdiv($before, 2));

                SystemLog::skipped(
                    'ai.clef_shadow.image_skipped',
                    reason: 'context_window',
                    input: ['model' => $model],
                    calc: ['images_before' => $before, 'images_after' => count($images)],
                );
            }
        }

        $withImages = $images !== [];
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
