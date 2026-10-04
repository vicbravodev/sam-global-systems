<?php

namespace App\Infrastructure\AI\Clef;

final readonly class ClefResponse
{
    /**
     * @param  array<string, array<string, mixed>>  $answers  una respuesta por id de pregunta
     */
    public function __construct(
        public string $model,
        public array $answers,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}
}
