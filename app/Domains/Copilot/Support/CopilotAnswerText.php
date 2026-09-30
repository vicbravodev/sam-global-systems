<?php

namespace App\Domains\Copilot\Support;

/**
 * The text a Copilot agent turn persists, from the text of each of its
 * steps. Models often answer in the same step where they call
 * suggest_followups and then return an empty (or filler-only) last step, so
 * the answer is every non-empty step text joined, not only the last one.
 * With no text at all it falls back to the grounded highlights the tools
 * collected (like TemplateCopilotNarrator) and only then to a fixed notice.
 *
 * Shared by the JSON turn and the streaming turn.
 */
final class CopilotAnswerText
{
    public const EMPTY = 'No pude completar la respuesta.';

    /**
     * @param  iterable<string|null>  $stepTexts  text of each step, in order
     */
    public static function compose(iterable $stepTexts, CopilotTurnCollector $collector): string
    {
        $texts = [];

        foreach ($stepTexts as $text) {
            $text = trim((string) $text);

            if ($text !== '' && end($texts) !== $text) {
                $texts[] = $text;
            }
        }

        if ($texts !== []) {
            return implode("\n\n", $texts);
        }

        $highlights = trim(implode(' ', $collector->highlights()));

        return $highlights !== '' ? $highlights : self::EMPTY;
    }
}
