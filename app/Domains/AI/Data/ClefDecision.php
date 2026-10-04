<?php

namespace App\Domains\AI\Data;

/**
 * Lo que decidió un modelo Clef sobre un evento (medición en sombra).
 */
final readonly class ClefDecision
{
    /**
     * @param  array<string, float>  $classificationProbabilities
     * @param  array<string, array<string, mixed>>|null  $mediaAnswers  null cuando no se enviaron imágenes
     */
    public function __construct(
        public string $model,
        public string $classification,
        public array $classificationProbabilities,
        public float $riskScore,
        public float $needsHumanProbability,
        public ?array $mediaAnswers,
        public int $imagesSent,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public float $costEstimate,
    ) {}
}
