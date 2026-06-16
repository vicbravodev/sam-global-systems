<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EventClassification;

/**
 * SPEC-09-SDK-DEFERRED: deterministic stand-in for the Laravel AI SDK agent.
 *
 * Returns a structured evaluation derived purely from the input context so the
 * rest of the pipeline (persistence, broadcasting, usage metering) can run
 * end-to-end in tests and in environments without the AI SDK configured.
 * When the real SDK lands, bind `EventEvaluationAgent` to its implementation
 * in `AIServiceProvider::register()` — no other code changes.
 */
class NullEventEvaluationAgent implements EventEvaluationAgent
{
    public bool $shouldFail = false;

    public EventClassification $forcedClassification = EventClassification::RealEvent;

    public float $forcedConfidence = 0.85;

    public function evaluate(AIInputContext $context): AIEvaluationResult
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('NullEventEvaluationAgent simulated failure');
        }

        $signals = $context->contextSignals;
        $priorityHint = (string) ($context->operationalProfile['risk_level'] ?? 'normal');

        $reasoning = [
            'received_normalized_event_'.$context->normalizedEventId,
            'inspected_context_signals_'.count($signals),
            'priority_hint_'.$priorityHint,
        ];

        $keyFactors = [
            'risk_level' => $priorityHint,
            'recent_event_count' => $context->recentHistory['event_count'] ?? 0,
        ];

        return new AIEvaluationResult(
            classification: $this->forcedClassification,
            confidenceScore: $this->forcedConfidence,
            riskScoreDelta: 0.0,
            explanationSummary: 'Evaluación determinista generada por NullEventEvaluationAgent.',
            reasoningSteps: $reasoning,
            keyFactors: $keyFactors,
            modelUsed: 'null-agent:1.0',
            inputTokens: 120,
            outputTokens: 60,
            latencyMs: 5,
            costEstimate: 0.0,
        );
    }
}
