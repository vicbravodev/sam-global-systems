<?php

namespace App\Domains\Copilot\Support;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Tokens the agent already spent in the turn, step by step, as each step
 * completes (the SDK's `StepCompleted` event, fed through CopilotAgent).
 * laravel/ai only reports the stream's usage once, at the very end: this is
 * what a turn that fails or is cut short has spent, so it can be billed.
 */
final class CopilotTurnUsage
{
    public TextUsage $usage;

    /**
     * Model that answered the last completed step (null before any step).
     */
    public ?string $model = null;

    public int $steps = 0;

    public function __construct()
    {
        $this->usage = new TextUsage;
    }

    public function add(TextUsage $usage, ?string $model): void
    {
        $this->usage = $this->usage->add($usage);
        $this->model = $model ?? $this->model;
        $this->steps++;
    }

    public function tokens(): int
    {
        return $this->usage->inputTokens + $this->usage->outputTokens;
    }
}
