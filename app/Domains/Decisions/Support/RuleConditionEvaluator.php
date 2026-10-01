<?php

namespace App\Domains\Decisions\Support;

use App\Support\LoggableCode;

class RuleConditionEvaluator
{
    /**
     * Every operator the leaf evaluator understands. Single source of truth
     * for validation rules and the visual condition builder.
     *
     * @var array<int, string>
     */
    public const OPERATORS = [
        'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
        'in', 'not_in', 'contains', 'is_null', 'is_not_null',
    ];

    /**
     * Evaluate a structured condition tree against a flat fact map.
     *
     * Conditions support `all` and `any` blocks containing leaf checks of the
     * form `{field, operator, value}`. Supported operators:
     * eq, neq, gt, gte, lt, lte, in, not_in, contains, is_null, is_not_null.
     *
     * A malformed node (a non-array child of `all`/`any`, a leaf whose field or
     * operator is not scalar, an unknown operator, an unrecognised shape) never
     * matches and never throws: a misconfigured rule must not take the decision
     * engine down. `problems()` reports those nodes.
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $facts
     */
    public function matches(array $conditions, array $facts): bool
    {
        if ($conditions === []) {
            return true;
        }

        if (isset($conditions['all']) && is_array($conditions['all'])) {
            foreach ($conditions['all'] as $child) {
                if (! is_array($child) || ! $this->matches($child, $facts)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($conditions['any']) && is_array($conditions['any'])) {
            foreach ($conditions['any'] as $child) {
                if (is_array($child) && $this->matches($child, $facts)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($conditions['field'], $conditions['operator'])) {
            if (! is_scalar($conditions['field']) || ! is_scalar($conditions['operator'])) {
                return false;
            }

            return $this->evaluateLeaf($conditions, $facts);
        }

        return false;
    }

    /**
     * Reports the invalid nodes of a condition tree. Every one of them evaluates
     * as `false` in `matches()` (`evaluates_as`): `malformed_condition`,
     * `unknown_operator`, `non_array_node` (a non-array child of `all`/`any`)
     * and `non_scalar_leaf` (a leaf whose field or operator is not scalar).
     * Pure: walks the tree with the same semantics as `matches()` and never
     * logs condition values.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<array{path: string, problem: string, operator: ?string, field: ?string, evaluates_as: false}>
     */
    public function problems(array $conditions, string $path = '$'): array
    {
        if ($conditions === []) {
            return [];
        }

        foreach (['all', 'any'] as $block) {
            if (isset($conditions[$block]) && is_array($conditions[$block])) {
                $problems = [];

                foreach ($conditions[$block] as $i => $child) {
                    $segment = is_int($i) ? $i : (LoggableCode::guard($i) ?? '?');
                    $childPath = "{$path}.{$block}.{$segment}";

                    if (! is_array($child)) {
                        $problems[] = ['path' => $childPath, 'problem' => 'non_array_node', 'operator' => null, 'field' => null, 'evaluates_as' => false];

                        continue;
                    }

                    array_push($problems, ...$this->problems($child, $childPath));
                }

                return $problems;
            }
        }

        if (isset($conditions['field'], $conditions['operator'])) {
            $operator = $conditions['operator'];
            $field = $conditions['field'];
            $scalar = is_scalar($operator) && is_scalar($field);

            if ($scalar && in_array((string) $operator, self::OPERATORS, true)) {
                return [];
            }

            return [[
                'path' => $path,
                'problem' => $scalar ? 'unknown_operator' : 'non_scalar_leaf',
                'operator' => is_scalar($operator) ? LoggableCode::guard((string) $operator) : null,
                'field' => is_scalar($field) ? LoggableCode::guard((string) $field) : null,
                'evaluates_as' => false,
            ]];
        }

        return [['path' => $path, 'problem' => 'malformed_condition', 'operator' => null, 'field' => null, 'evaluates_as' => false]];
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<string, mixed>  $facts
     */
    private function evaluateLeaf(array $leaf, array $facts): bool
    {
        $field = (string) $leaf['field'];
        $operator = (string) $leaf['operator'];
        $expected = $leaf['value'] ?? null;
        $actual = $facts[$field] ?? null;

        return match ($operator) {
            'eq' => self::looselyEquals($actual, $expected),
            'neq' => ! self::looselyEquals($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in' => is_array($expected) && self::containsLoosely($expected, $actual),
            'not_in' => is_array($expected) && ! self::containsLoosely($expected, $actual),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            default => false,
        };
    }

    /**
     * @param  array<mixed>  $haystack
     */
    private static function containsLoosely(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $item) {
            if (self::looselyEquals($needle, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Igualdad de `eq`/`neq`/`in`/`not_in`. Reproduce explícitamente la
     * comparación `==` de PHP 8 con la que se escribieron las reglas del
     * tenant: los hechos son null|bool|int|float|string y los valores llegan
     * del JSON de la regla (el builder manda números como número, pero el
     * editor JSON y las reglas viejas pueden traer '5' o 'true'). Así:
     *
     * - bool contra cualquier cosa compara verdad: `false` casa con null, 0,
     *   '' y '0' (un hecho de cámara aún indeterminado, null, casa `eq false`).
     * - null contra string sólo casa con ''; contra número, con 0; contra
     *   array, con [].
     * - número contra string numérico ('5', ' 5', '5.0', '1e1') compara como
     *   número; contra string no numérico, compara el número como texto.
     * - dos strings numéricos comparan como número ('5' casa '5.0'); si no,
     *   texto exacto.
     */
    private static function looselyEquals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if ($a === null || $b === null) {
            $other = $a ?? $b;

            return match (true) {
                $other === null => true,
                is_string($other) => $other === '',
                is_int($other), is_float($other) => (bool) $other === false,
                is_array($other) => $other === [],
                default => false,
            };
        }

        if (is_array($a) || is_array($b)) {
            return is_array($a) && is_array($b) && self::arraysLooselyEqual($a, $b);
        }

        $aIsNumber = is_int($a) || is_float($a);
        $bIsNumber = is_int($b) || is_float($b);

        if ($aIsNumber || $bIsNumber || (is_string($a) && is_string($b) && is_numeric($a) && is_numeric($b))) {
            if (is_numeric($a) && is_numeric($b)) {
                return self::numbersEqual($a, $b);
            }

            // Número contra string no numérico: PHP 8 compara el número como texto.
            if ($aIsNumber && is_string($b)) {
                return (string) $a === $b;
            }

            if ($bIsNumber && is_string($a)) {
                return $a === (string) $b;
            }

            return false;
        }

        return $a === $b;
    }

    /**
     * Ambos lados son numéricos (int, float o string numérico). Se comparan
     * como float, exacto para todo hecho de regla (scores, conteos e ids
     * muy por debajo de 2^53).
     */
    private static function numbersEqual(int|float|string $a, int|float|string $b): bool
    {
        return (float) $a === (float) $b;
    }

    /**
     * @param  array<mixed>  $a
     * @param  array<mixed>  $b
     */
    private static function arraysLooselyEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! array_key_exists($key, $b) || ! self::looselyEquals($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }
}
