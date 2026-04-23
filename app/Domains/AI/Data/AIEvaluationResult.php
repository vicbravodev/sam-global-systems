<?php

namespace App\Domains\AI\Data;

use App\Domains\AI\Enums\EventClassification;

/**
 * Result returned by an `EventEvaluationAgent` implementation.
 */
final readonly class AIEvaluationResult
{
    /**
     * @param  array<int, string>  $reasoningSteps
     * @param  array<string, mixed>  $keyFactors
     */
    public function __construct(
        public EventClassification $classification,
        public float $confidenceScore,
        public float $riskScoreDelta,
        public string $explanationSummary,
        public array $reasoningSteps,
        public array $keyFactors,
        public string $modelUsed,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public float $costEstimate,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
