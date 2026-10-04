<?php

namespace App\Domains\Copilot\Support;

use Illuminate\Support\Str;

/**
 * Cleans the follow-up questions the model proposes before they become
 * chips. A chip is sent as the user's own message, so it must read like
 * something the user would ask ("¿Dónde está la T-77 ahora?"), never like
 * an offer of the assistant ("¿Quieres que revise…?", "¿Te comparo…?"):
 * those are dropped, as are duplicates and questions already asked in the
 * conversation.
 */
final class CopilotFollowups
{
    public const MAX = 3;

    public const MAX_CHARS = 80;

    /**
     * Openings of an assistant offer, compared without accents nor "¿¡".
     */
    private const OFFER = '/^(?:si (?:quieres|gustas|deseas)|quieres|quiere|quieren|quisieras|deseas|desea|gustas|te |le |les |puedo|podria|me permites|prefieres|reviso|revisamos|busco|buscamos|abro|comparo|muestro|preparo|separo|detallo|genero|armo|hago|valido|confirmo|agrego|calculo|consulto|analizo|sigo|vemos)\b/u';

    /**
     * @param  list<mixed>  $questions  as the model sent them
     * @param  list<string>  $asked  questions already asked in the conversation
     * @return array{kept: list<string>, dropped: int}
     */
    public static function clean(array $questions, array $asked = []): array
    {
        $seen = array_fill_keys(array_map(self::key(...), $asked), true);
        $kept = [];
        $dropped = 0;

        foreach ($questions as $question) {
            if (! is_string($question) || trim($question) === '') {
                continue;
            }

            $question = mb_substr(trim($question), 0, self::MAX_CHARS);
            $key = self::key($question);

            if (self::isOffer($question) || isset($seen[$key]) || count($kept) >= self::MAX) {
                $dropped++;

                continue;
            }

            $seen[$key] = true;
            $kept[] = $question;
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    public static function isOffer(string $question): bool
    {
        $plain = ltrim(Str::lower(Str::ascii(trim($question))), '?!¿¡ "\'');

        return preg_match(self::OFFER, $plain) === 1;
    }

    private static function key(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }
}
