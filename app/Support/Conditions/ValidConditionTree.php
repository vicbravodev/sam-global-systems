<?php

namespace App\Support\Conditions;

use App\Domains\Decisions\Support\RuleConditionEvaluator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the recursive `all`/`any` condition tree consumed by
 * RuleConditionEvaluator. An empty tree is valid (matches everything);
 * every other node must be an `all`/`any` group or a complete leaf.
 */
class ValidConditionTree implements ValidationRule
{
    private const MAX_DEPTH = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Las condiciones deben ser una estructura de condiciones válida.');

            return;
        }

        if ($value === []) {
            return;
        }

        $error = $this->validateNode($value, 0);

        if ($error !== null) {
            $fail($error);
        }
    }

    /**
     * @param  array<mixed>  $node
     */
    private function validateNode(array $node, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            return 'Las condiciones superan la profundidad máxima permitida ('.self::MAX_DEPTH.' niveles).';
        }

        $isGroup = array_key_exists('all', $node) || array_key_exists('any', $node);

        if ($isGroup) {
            if (array_key_exists('all', $node) && array_key_exists('any', $node)) {
                return 'Un grupo de condiciones no puede combinar "all" y "any" en el mismo nivel.';
            }

            $children = $node['all'] ?? $node['any'] ?? null;

            if (! is_array($children) || $children === [] || ! array_is_list($children)) {
                return 'Un grupo de condiciones debe contener una lista con al menos una condición.';
            }

            foreach ($children as $child) {
                if (! is_array($child)) {
                    return 'Cada condición del grupo debe ser una estructura válida.';
                }

                $error = $this->validateNode($child, $depth + 1);

                if ($error !== null) {
                    return $error;
                }
            }

            return null;
        }

        return $this->validateLeaf($node);
    }

    /**
     * @param  array<mixed>  $leaf
     */
    private function validateLeaf(array $leaf): ?string
    {
        $field = $leaf['field'] ?? null;
        $operator = $leaf['operator'] ?? null;

        if (! is_string($field) || $field === '') {
            return 'Cada condición debe indicar el campo a evaluar.';
        }

        if (! is_string($operator) || ! in_array($operator, RuleConditionEvaluator::OPERATORS, true)) {
            return 'La condición sobre "'.$field.'" usa un operador no soportado.';
        }

        if (in_array($operator, ['is_null', 'is_not_null'], true)) {
            return null;
        }

        if (! array_key_exists('value', $leaf)) {
            return 'La condición sobre "'.$field.'" necesita un valor de comparación.';
        }

        $value = $leaf['value'];

        if (in_array($operator, ['in', 'not_in'], true)) {
            if (! is_array($value) || $value === [] || ! array_is_list($value)) {
                return 'La condición sobre "'.$field.'" necesita una lista de valores.';
            }

            foreach ($value as $item) {
                if (! is_scalar($item)) {
                    return 'La lista de valores de "'.$field.'" solo admite valores simples.';
                }
            }

            return null;
        }

        if ($value !== null && ! is_scalar($value)) {
            return 'El valor de comparación de "'.$field.'" debe ser un valor simple.';
        }

        return null;
    }
}
