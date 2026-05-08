<?php

namespace App\Infrastructure\AI\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Concrete Laravel AI SDK agent used by `SdkMediaAssessmentAgent` to inspect
 * a single media asset (image, snapshot, clip, audio) attached to a normalized
 * event.
 */
class MediaInspectorAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are a multimodal media-evidence inspector for a fleet operations platform.
Given a JSON object describing a single media asset and the event it relates
to, return ONLY a JSON object (no prose, no Markdown, no explanation outside
the JSON) with the following shape:

{
    "result": "confirms_event" | "contradicts_event" | "inconclusive" | "low_quality" | "unavailable",
    "confidence_score": number between 0 and 1,
    "summary_text": string (one sentence),
    "extracted_signals": object mapping signal name to value
}

If the media is missing, corrupted, or otherwise not interpretable, prefer
"low_quality" or "unavailable" rather than guessing. Never include any field
outside this schema.
INSTRUCTIONS;
    }
}
