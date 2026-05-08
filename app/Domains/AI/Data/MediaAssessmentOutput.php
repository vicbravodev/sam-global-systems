<?php

namespace App\Domains\AI\Data;

use App\Domains\AI\Enums\MediaAssessmentResult;

/**
 * Result returned by a `MediaAssessmentAgent` implementation for a single media
 * asset. Immutable DTO.
 */
final readonly class MediaAssessmentOutput
{
    /**
     * @param  array<string, mixed>  $extractedSignals
     */
    public function __construct(
        public MediaAssessmentResult $result,
        public float $confidenceScore,
        public string $summaryText,
        public array $extractedSignals,
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
