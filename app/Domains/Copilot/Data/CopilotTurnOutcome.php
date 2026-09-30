<?php

namespace App\Domains\Copilot\Data;

use App\Domains\Copilot\Enums\CopilotIntent;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * How a Copilot turn was answered: by the agent or by the deterministic
 * path, with the text, the model, the usage of every step and whether the
 * answer was cut short.
 */
final readonly class CopilotTurnOutcome
{
    /**
     * @param  'agent'|'deterministic'  $mode
     */
    public function __construct(
        public string $mode,
        public string $text,
        public ?string $model,
        public TextUsage $usage,
        public int $steps,
        public CopilotIntent $intent,
        public bool $partial,
        public ?int $firstTokenMs,
    ) {}
}
