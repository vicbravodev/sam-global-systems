<?php

namespace App\Support\Conditions;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the flat-equality condition dictionaries used by mapping rules,
 * automation triggers and escalation configs: `{clave: valor_esperado}`
 * evaluated as AND of equality checks. Keys may be dot-paths into payloads.
 */
class ValidFlatConditions implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Las condiciones deben ser un mapa de campo y valor esperado.');

            return;
        }

        if ($value === []) {
            return;
        }

        if (array_is_list($value)) {
            $fail('Las condiciones deben ser un mapa de campo y valor esperado, no una lista.');

            return;
        }

        foreach ($value as $key => $expected) {
            if (! is_string($key) || trim($key) === '') {
                $fail('Cada condición debe indicar el campo a comparar.');

                return;
            }

            if ($expected !== null && ! is_scalar($expected)) {
                $fail('El valor esperado de "'.$key.'" debe ser un valor simple (texto, número o booleano).');

                return;
            }
        }
    }
}
