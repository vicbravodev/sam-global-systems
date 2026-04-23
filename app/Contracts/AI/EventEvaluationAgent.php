<?php

namespace App\Contracts\AI;

use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;

interface EventEvaluationAgent
{
    /**
     * Run the AI textual evaluation pipeline.
     *
     * Implementations may stream progress via broadcasting but MUST return a
     * deterministic `AIEvaluationResult` aggregating the final state.
     *
     * @throws \RuntimeException When the underlying provider fails in a way
     *                           the caller should treat as "fallback to rules_only".
     */
    public function evaluate(AIInputContext $context): AIEvaluationResult;
}
