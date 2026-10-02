<?php

namespace App\Domains\Notifications\Support;

/**
 * Texto de SMS en el alfabeto GSM-7: un solo carácter fuera de él (á, í, ó,
 * ú, "…", comillas tipográficas, un emoji) cambia TODO el mensaje a UCS-2,
 * donde un segmento son 70 caracteres en vez de 160 — el mismo aviso cuesta
 * hasta 3 veces más. Se translitera lo que tiene equivalente y se quita lo
 * demás; é, ñ, ü, ¿ y ¡ sí son GSM-7 y se conservan.
 */
final class SmsText
{
    /**
     * Alfabeto GSM 03.38 básico (sin la tabla de extensión, que cuesta dos
     * caracteres por símbolo).
     */
    private const string GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** @var array<string, string> */
    private const array TRANSLITERATE = [
        'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ç' => 'c', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U', 'À' => 'A',
        '…' => '...', '–' => '-', '—' => '-', '•' => '-',
        '“' => '"', '”' => '"', '„' => '"', '‘' => "'", '’' => "'", '´' => "'",
        "\u{00A0}" => ' ', "\t" => ' ',
    ];

    public static function gsm7(string $text): string
    {
        $text = strtr($text, self::TRANSLITERATE);
        $out = '';

        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::GSM7, $char) !== false) {
                $out .= $char;
            }
        }

        // Quitar emojis puede dejar dobles espacios o un espacio inicial.
        return trim((string) preg_replace('/ {2,}/', ' ', $out));
    }

    public static function isGsm7(string $text): bool
    {
        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::GSM7, $char) === false) {
                return false;
            }
        }

        return true;
    }
}
