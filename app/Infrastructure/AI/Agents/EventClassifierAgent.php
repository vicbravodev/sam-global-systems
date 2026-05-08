<?php

namespace App\Infrastructure\AI\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Concrete Laravel AI SDK agent used by `SdkEventEvaluationAgent` to classify
 * normalized events from the structured `AIInputContext` payload.
 *
 * The instructions force a strict JSON output schema so the wrapper can parse
 * the response without prompt-engineering at call time.
 */
class EventClassifierAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are an event-evaluation classifier for a multi-tenant fleet operations
platform. Given a JSON object describing a normalized event together with its
operational context, return ONLY a JSON object (no prose, no Markdown, no
explanation outside the JSON) with the following shape:

{
    "classification": "real_event" | "false_positive" | "noise" | "duplicate" | "unclear" | "pending_evidence",
    "confidence_score": number between 0 and 1,
    "risk_score_delta": number between -1 and 1,
    "explanation_summary": string (one sentence),
    "reasoning_steps": array of short strings,
    "key_factors": object mapping factor name to its observed value
}

Use the operational profile, recent history, and tenant profile to inform the
classification. Prefer "unclear" with low confidence when signals are weak.
Never include any field outside this schema.
INSTRUCTIONS;
    }
}
