<?php

namespace App\Infrastructure\AI\Middleware;

use App\Support\SystemLog;
use Closure;
use Laravel\Ai\PendingStep;
use Laravel\Ai\ToolChoice;

/**
 * Per-step guard of the Copilot agent loop. On the last allowed step, or once
 * the turn has spent its token budget, the model is forced to answer with the
 * data it already has (tool choice `none`) instead of calling more tools.
 */
final class CopilotStepGuard
{
    public function __construct(
        private readonly int $teamId,
        private readonly int $maxTurnTokens,
    ) {}

    public function handle(PendingStep $step, Closure $next): mixed
    {
        $tokens = $step->usage->inputTokens + $step->usage->outputTokens;

        $reason = match (true) {
            $step->isFinalStep => 'max_steps',
            $tokens >= $this->maxTurnTokens => 'max_turn_tokens',
            default => null,
        };

        if ($reason !== null) {
            SystemLog::skipped(
                'copilot.step.budget_reached',
                $reason,
                ['team_id' => $this->teamId, 'step' => $step->number],
                calc: ['tokens_so_far' => $tokens, 'max_turn_tokens' => $this->maxTurnTokens],
            );

            return $next($step->withToolChoice(ToolChoice::none));
        }

        SystemLog::ok('copilot.step.started', ['team_id' => $this->teamId, 'step' => $step->number, 'tools_available' => count($step->tools)], debug: true);

        return $next($step);
    }
}
