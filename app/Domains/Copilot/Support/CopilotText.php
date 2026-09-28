<?php

namespace App\Domains\Copilot\Support;

use Illuminate\Support\Str;

final class CopilotText
{
    /**
     * Lowercase ASCII version of the prompt so regexes don't have to care
     * about accents ("dónde" / "donde", "pánico" / "panico").
     */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * Compact alphanumeric key used to compare asset codes: "T-555", "t 555"
     * and "T555" all become "t555".
     */
    public static function key(?string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', self::normalize((string) $text));
    }
}
