<?php

namespace App\Domains\Copilot\Data;

/**
 * The human-readable reply plus what it cost to produce.
 */
final readonly class CopilotNarration
{
    public function __construct(
        public string $text,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $costEstimate = 0.0,
    ) {}
}
