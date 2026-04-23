<?php

namespace App\Domains\AI\Data;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;

class AIEvaluationResult
{
    /**
     * @param  array<int, array{code: string, value: string, weight?: float, description?: string}>  $signals
     * @param  array<string, mixed>  $reasoningSteps
     * @param  array<string, mixed>  $keyFactors
     * @param  array<string, mixed>  $confidenceBreakdown
     * @param  array<string, mixed>  $evidenceSummary
     */
    public function __construct(
        public readonly EvaluationMode $mode,
        public readonly EventClassification $classification,
        public readonly float $confidenceScore,
        public readonly float $riskScore,
        public readonly EvaluationPriority $priorityLevel,
        public readonly ?bool $isRealEvent,
        public readonly bool $requiresAction,
        public readonly string $modelUsed,
        public readonly string $explanationSummary,
        public readonly array $signals = [],
        public readonly array $reasoningSteps = [],
        public readonly array $keyFactors = [],
        public readonly array $confidenceBreakdown = [],
        public readonly array $evidenceSummary = [],
        public readonly int $tokensUsed = 0,
        public readonly int $latencyMs = 0,
        public readonly float $costEstimate = 0.0,
    ) {}
}
