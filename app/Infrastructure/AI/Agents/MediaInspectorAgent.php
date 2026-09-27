<?php

namespace App\Infrastructure\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Concrete Laravel AI SDK agent used by `SdkMediaAssessmentAgent` to inspect
 * a single media asset (image, snapshot, clip, audio) attached to a normalized
 * event.
 */
class MediaInspectorAgent implements Agent, HasStructuredOutput
{
    /** @var list<string> */
    public const array RESULTS = ['confirms_event', 'contradicts_event', 'inconclusive', 'low_quality', 'unavailable'];

    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are a multimodal media-evidence inspector for a fleet security-monitoring
platform operating in Mexico. The events under review are typically panic
buttons, possible robbery or assault, vehicle theft, cargo theft, or vehicle
misuse — your description of what is visible is evidence that a decision
engine and human operators rely on to confirm or discard the alert.

LANGUAGE — MANDATORY: `summary_text` MUST be written in natural Spanish
(español de México), never English. Describe concretely what is visible in the
media. The `result` enum values and the keys inside `extracted_signals` stay
exactly as listed (they are machine identifiers); only `summary_text` is prose
and it is always in Spanish.

Given a JSON object describing a single media asset and the event it relates
to, return ONLY a JSON object (no prose, no Markdown, no explanation outside
the JSON) with the following shape:

{
    "result": "confirms_event" | "contradicts_event" | "inconclusive" | "low_quality" | "unavailable",
    "confidence_score": number between 0 and 1,
    "summary_text": string (one sentence, in Spanish, describing exactly what is visible),
    "extracted_signals": {
        "persons_visible_count": integer or null,
        "passenger_detected": boolean or null,
        "driver_visible": boolean or null,
        "visible_threat": boolean or null,
        "cabin_appears_normal": boolean or null,
        "vehicle_moving": boolean or null,
        ...any additional relevant signal
    }
}

Signal semantics — always include every key above, using null when the media
does not allow a determination:
- "persons_visible_count": total people visible in the frame.
- "passenger_detected": someone besides the driver is inside the cab.
- "driver_visible": the driver can be seen.
- "visible_threat": a weapon, physical aggression, a struggle, forced entry,
  raised hands, or clear signs of distress or coercion are visible.
- "cabin_appears_normal": the cab looks routine (driver seated normally,
  no disorder, no strangers); false when anything looks wrong.
- "vehicle_moving": the vehicle appears to be in motion.

If the media is missing, corrupted, or otherwise not interpretable, prefer
"low_quality" or "unavailable" rather than guessing. Never include any field
outside this schema.
INSTRUCTIONS;
    }

    /**
     * Native structured-output schema. The fixed signals are nullable; any
     * extra signal travels in `additional_signals` as `{name, value}` pairs
     * (the SDK disables free-form object keys).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'result' => $schema->string()->enum(self::RESULTS)->required(),
            'confidence_score' => $schema->number()->min(0)->max(1)->required(),
            'summary_text' => $schema->string()->description('Una oración en español.')->required(),
            'extracted_signals' => $schema->object([
                'persons_visible_count' => $schema->integer()->nullable()->required(),
                'passenger_detected' => $schema->boolean()->nullable()->required(),
                'driver_visible' => $schema->boolean()->nullable()->required(),
                'visible_threat' => $schema->boolean()->nullable()->required(),
                'cabin_appears_normal' => $schema->boolean()->nullable()->required(),
                'vehicle_moving' => $schema->boolean()->nullable()->required(),
                'additional_signals' => $schema->array()->items($schema->object([
                    'name' => $schema->string()->required(),
                    'value' => $schema->string()->required(),
                ]))->required(),
            ])->required(),
        ];
    }
}
