<?php

namespace App\Domains\Notifications\Support;

/**
 * Cómo habla SAM por teléfono: un solo lugar para la voz, el idioma y el
 * ritmo de todo `<Say>` (verificación de pánico, avisos de incidente,
 * respuestas a una tecla).
 *
 * Voz por defecto `Polly.Mia-Neural` (es-MX, la más natural con SSML
 * completo); `Polly.Mía-Generative` suena más cálida pero cuesta ~4× y sigue
 * en beta. Cada frase va en su propio `<prosody rate>` (las voces generativas
 * sólo aceptan prosody envolviendo frases completas) y entre frases hay una
 * pausa breve, para que no suene apresurado. Las voces básicas
 * (`Polly.Woman`, `man`, `alice`…) no aceptan SSML: ahí sale texto plano.
 */
final class TwilioSpeech
{
    public const string DEFAULT_VOICE = 'Polly.Mia-Neural';

    public const string DEFAULT_RATE = '90%';

    public const string LANGUAGE = 'es-MX';

    /** Pausa entre frases dentro de un mismo `<Say>`. */
    private const string SENTENCE_BREAK = '<break time="400ms"/>';

    /**
     * Un `<Say>` con la voz de SAM. Acepta frases sueltas o un texto que se
     * parte en frases por su puntuación.
     *
     * @param  string|list<string>  $text
     */
    public static function say(string|array $text): string
    {
        $sentences = self::sentences($text);
        $voice = self::voice();

        if (! self::supportsSsml($voice)) {
            return '<Say voice="'.self::escape($voice).'" language="'.self::LANGUAGE.'">'
                .self::escape(implode(' ', $sentences))
                .'</Say>';
        }

        $rate = self::rate();
        $body = implode(self::SENTENCE_BREAK, array_map(
            fn (string $sentence) => '<prosody rate="'.$rate.'">'.self::escape($sentence).'</prosody>',
            $sentences,
        ));

        return '<Say voice="'.self::escape($voice).'" language="'.self::LANGUAGE.'">'.$body.'</Say>';
    }

    /**
     * Documento TwiML completo que sólo dice algo y cuelga.
     *
     * @param  string|list<string>  $text
     */
    public static function response(string|array $text): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.self::say($text).'</Response>';
    }

    public static function voice(): string
    {
        $voice = config('services.twilio.tts_voice');

        return is_string($voice) && trim($voice) !== '' ? trim($voice) : self::DEFAULT_VOICE;
    }

    /**
     * Velocidad de lectura en porcentaje (20%–200%, lo que acepta Polly);
     * un valor fuera de rango o mal escrito cae al de SAM.
     */
    public static function rate(): string
    {
        $rate = config('services.twilio.tts_rate');

        if (! is_string($rate) || preg_match('/^(\d{2,3})%$/', trim($rate), $matches) !== 1) {
            return self::DEFAULT_RATE;
        }

        $percent = (int) $matches[1];

        return $percent >= 20 && $percent <= 200 ? $percent.'%' : self::DEFAULT_RATE;
    }

    /**
     * Sólo Polly y Google interpretan SSML dentro de `<Say>`; con las voces
     * básicas una etiqueta haría que Twilio leyera el marcado o fallara.
     */
    private static function supportsSsml(string $voice): bool
    {
        return (str_starts_with($voice, 'Polly.') && ! in_array($voice, ['Polly.Woman', 'Polly.Man'], true))
            || str_starts_with($voice, 'Google.');
    }

    /**
     * @param  string|list<string>  $text
     * @return list<string>
     */
    private static function sentences(string|array $text): array
    {
        $parts = is_array($text) ? $text : preg_split('/(?<=[.!?])\s+/u', trim($text));
        $parts = $parts === false ? [] : $parts;

        return array_values(array_filter(
            array_map(fn (string $part) => trim($part), $parts),
            fn (string $part) => $part !== '',
        ));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
