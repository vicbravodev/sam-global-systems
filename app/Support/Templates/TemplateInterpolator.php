<?php

namespace App\Support\Templates;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Interpolador estricto para plantillas que edita un tenant (notificaciones,
 * plantillas de acción de automatización).
 *
 * NUNCA compilar esas plantillas con Blade: `{{ }}` y `@php` de Blade son PHP
 * y convertirían una plantilla guardada en ejecución remota de código. Aquí
 * sólo se sustituyen variables:
 *
 *   {{ $nombre }}  {{ nombre }}  {{ ruta.anidada }}  (también {!! ... !!})
 *
 * Cualquier otra expresión entre llaves se descarta (se renderiza vacía) y las
 * directivas `@...` quedan como texto inerte. El resultado es texto plano:
 * quien lo pinte como HTML (el correo) debe escaparlo al pintarlo, o pedir
 * `escapeHtml: true`. Los valores interpolados no se re-interpretan.
 */
class TemplateInterpolator
{
    private const EXPRESSION = '/\{\{\s*(.*?)\s*\}\}|\{!!\s*(.*?)\s*!!\}/s';

    private const VARIABLE = '/^\$?([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*)$/';

    private const MAX_STRUCTURED_LENGTH = 200;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function render(string $template, array $variables, bool $escapeHtml = false): string
    {
        return (string) preg_replace_callback(
            self::EXPRESSION,
            function (array $matches) use ($variables, $escapeHtml): string {
                $expression = trim($matches[1] !== '' ? $matches[1] : ($matches[2] ?? ''));

                if (preg_match(self::VARIABLE, $expression, $variable) !== 1) {
                    return '';
                }

                $value = $this->stringify(Arr::get($variables, $variable[1]));

                return $escapeHtml ? e($value) : $value;
            },
            $template,
        );
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'sí' : 'no',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            default => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::MAX_STRUCTURED_LENGTH),
        };
    }
}
