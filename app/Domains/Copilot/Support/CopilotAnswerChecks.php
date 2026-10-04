<?php

namespace App\Domains\Copilot\Support;

use Illuminate\Support\Str;

/**
 * The conversation-quality checks `copilot:eval` runs on every answer: the
 * things that made the Copilot feel clumsy, written down so a prompt or a
 * model change can be measured instead of eyeballed. Each check returns
 * null when it passes or a short Spanish reason when it fails.
 */
final class CopilotAnswerChecks
{
    public const DEFAULT_MAX_CHARS = 900;

    /**
     * Share of an answer's sentences allowed to restate the previous answer.
     */
    public const MAX_REPEATED_SHARE = 0.34;

    /**
     * Word-trigram similarity from which two sentences count as the same.
     */
    private const SAME_SENTENCE = 0.6;

    private const PREAMBLE = '/^(claro|por supuesto|con gusto|perfecto|aqui tienes|aqui esta|voy a|dejame|entendido|buena pregunta)\b/u';

    private const FORMULA = '/(siguiente paso recomendado\s*:|^\s*riesgos?\s*:|^\s*anomal[ií]as?\s*:)/imu';

    private const RAW_TIME = '/(\bUTC\b|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}|[+-]00:00\b)/u';

    /**
     * @param  list<string>  $followups
     * @param  list<string>  $askedBefore  questions asked earlier in the conversation, including this one
     * @return array<string, string|null> check => null (pass) or why it failed
     */
    public static function run(string $answer, ?string $previousAnswer, array $followups, array $askedBefore, int $maxChars = self::DEFAULT_MAX_CHARS): array
    {
        return [
            'respondio' => self::answered($answer),
            'hora_local' => self::localTimes($answer),
            'sin_preambulo' => self::noPreamble($answer),
            'sin_formula' => self::noFormula($answer),
            'largo' => self::length($answer, $maxChars),
            'no_repite' => self::noRepetition($answer, $previousAnswer),
            'pills_presentes' => $followups === [] ? 'sin preguntas de seguimiento' : null,
            'pills_voz_usuario' => self::userVoice($followups),
            'pills_nuevas' => self::newQuestions($followups, $askedBefore),
        ];
    }

    public static function answered(string $answer): ?string
    {
        return trim($answer) === '' || trim($answer) === CopilotAnswerText::EMPTY ? 'respuesta vacía' : null;
    }

    public static function localTimes(string $answer): ?string
    {
        return preg_match(self::RAW_TIME, $answer, $m) === 1 ? "hora cruda: \"{$m[0]}\"" : null;
    }

    public static function noPreamble(string $answer): ?string
    {
        $start = Str::lower(Str::ascii(ltrim(strip_tags($answer), " *_¡\n")));

        return preg_match(self::PREAMBLE, $start, $m) === 1 ? "preámbulo: \"{$m[0]}\"" : null;
    }

    public static function noFormula(string $answer): ?string
    {
        return preg_match(self::FORMULA, $answer, $m) === 1 ? 'fórmula fija: "'.trim($m[0]).'"' : null;
    }

    public static function length(string $answer, int $maxChars): ?string
    {
        $chars = mb_strlen(trim($answer));

        return $chars > $maxChars ? "{$chars} caracteres (máx {$maxChars})" : null;
    }

    public static function noRepetition(string $answer, ?string $previousAnswer): ?string
    {
        $share = self::repeatedShare($answer, $previousAnswer);

        return $share > self::MAX_REPEATED_SHARE ? sprintf('repite %d%% de la respuesta anterior', (int) round($share * 100)) : null;
    }

    /**
     * @param  list<string>  $followups
     */
    public static function userVoice(array $followups): ?string
    {
        $offers = array_values(array_filter($followups, CopilotFollowups::isOffer(...)));

        return $offers !== [] ? 'pill como ofrecimiento: "'.$offers[0].'"' : null;
    }

    /**
     * @param  list<string>  $followups
     * @param  list<string>  $askedBefore
     */
    public static function newQuestions(array $followups, array $askedBefore): ?string
    {
        $kept = CopilotFollowups::clean($followups, $askedBefore)['kept'];

        return count($kept) < min(count($followups), CopilotFollowups::MAX) ? 'pill repetida o ya preguntada' : null;
    }

    /**
     * Share of the answer's sentences that restate a sentence of the
     * previous answer (word trigrams, accents and case ignored).
     */
    public static function repeatedShare(string $answer, ?string $previousAnswer): float
    {
        if ($previousAnswer === null || trim($previousAnswer) === '') {
            return 0.0;
        }

        $current = self::sentences($answer);
        $previous = array_map(self::trigrams(...), self::sentences($previousAnswer));

        if ($current === [] || $previous === []) {
            return 0.0;
        }

        $repeated = 0;

        foreach ($current as $sentence) {
            $grams = self::trigrams($sentence);

            foreach ($previous as $other) {
                if (self::jaccard($grams, $other) >= self::SAME_SENTENCE) {
                    $repeated++;

                    break;
                }
            }
        }

        return $repeated / count($current);
    }

    /**
     * @return list<string> sentences long enough to carry content
     */
    private static function sentences(string $text): array
    {
        $plain = Str::lower(Str::ascii(str_replace(['**', '__', '`'], '', $text)));
        $parts = preg_split('/(?<=[.!?;])\s+|\n+/u', $plain);
        $parts = $parts !== false ? $parts : [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $s) => mb_strlen($s) >= 25));
    }

    /**
     * @return array<string, true>
     */
    private static function trigrams(string $sentence): array
    {
        $words = preg_split('/[^a-z0-9%]+/', $sentence, flags: PREG_SPLIT_NO_EMPTY);
        $words = $words !== false ? $words : [];
        $grams = [];

        for ($i = 0; $i + 2 < count($words); $i++) {
            $grams[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $grams;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect_key($a, $b)) / count($a + $b);
    }
}
