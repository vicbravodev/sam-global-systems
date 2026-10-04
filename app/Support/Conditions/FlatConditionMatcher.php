<?php

namespace App\Support\Conditions;

use Illuminate\Support\Arr;

/**
 * Evalúa un mapa plano de condiciones (`{ruta: valor_esperado}`, AND de
 * igualdades; ver {@see ValidFlatConditions}) contra un payload, como lo hacen
 * las reglas de mapeo de eventos del proveedor.
 *
 * - Una ruta con `*` recorre listas (`data.conditions.*.triggerId`) y se cumple
 *   si ALGÚN elemento es igual al esperado: un `AlertIncident` de Samsara puede
 *   traer varias condiciones y la que importa no tiene por qué ser la primera.
 * - Los escalares se comparan por su forma de texto: `1034` (entero del payload)
 *   y `"1034"` (lo que escribe un operador en la UI) son iguales. Booleanos y
 *   null se comparan estrictos, para que `"true"` no case con `true`.
 */
class FlatConditionMatcher
{
    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>|null  $payload
     * @return string|null null si todas se cumplen; si no, la primera ruta que
     *                     falla (`*` cuando no hay payload contra el que evaluar)
     */
    public function firstFailedPath(array $conditions, ?array $payload): ?string
    {
        if ($conditions === []) {
            return null;
        }

        if ($payload === null) {
            return '*';
        }

        foreach ($conditions as $path => $expected) {
            if (! $this->holds($payload, $path, $expected)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function holds(array $payload, string $path, mixed $expected): bool
    {
        if (! str_contains($path, '*')) {
            return self::equals(Arr::get($payload, $path), $expected);
        }

        $values = data_get($payload, $path);

        if (! is_array($values)) {
            return false;
        }

        // data_get ya aplana los comodines anidados (`a.*.b.*.c`); un elemento
        // que siga siendo array no es un escalar comparable y no casa.
        foreach ($values as $value) {
            if (self::equals($value, $expected)) {
                return true;
            }
        }

        return false;
    }

    public static function equals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected) || $actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        return (string) $actual === (string) $expected;
    }
}
