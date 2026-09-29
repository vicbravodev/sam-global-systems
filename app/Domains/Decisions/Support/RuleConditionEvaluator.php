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
                if (! $this->matches($child, $facts)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($conditions['any']) && is_array($conditions['any'])) {
            foreach ($conditions['any'] as $child) {
                if ($this->matches($child, $facts)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($conditions['field'], $conditions['operator'])) {
            return $this->evaluateLeaf($conditions, $facts);
        }

        return false;
    }

    /**
     * Reports the invalid nodes of a condition tree and what `matches()` does
     * when it reaches each one (`evaluates_as`):
     * - `malformed_condition` / `unknown_operator` → `false` (silently no match);
     * - `unknown_operator` with a non-scalar operator or field → `'error_exception'`
     *   (the string cast warns, and Laravel's handler turns it into an ErrorException);
     * - `non_array_node` (a non-array child of `all`/`any`) → `'type_error'`:
     *   `matches()` throws a TypeError when it reaches it (it may short-circuit
     *   before). That crash is known and deliberately left as is here.
     * Pure: walks the tree with the same semantics as `matches()` and never
     * logs condition values.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<array{path: string, problem: string, operator: ?string, field: ?string, evaluates_as: false|string}>
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
                    $segment = is_int($i) ? $i : (LoggableCode::guard((string) $i) ?? '?');
                    $childPath = "{$path}.{$block}.{$segment}";

                    if (! is_array($child)) {
                        $problems[] = ['path' => $childPath, 'problem' => 'non_array_node', 'operator' => null, 'field' => null, 'evaluates_as' => 'type_error'];

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

            if (is_scalar($operator) && in_array((string) $operator, self::OPERATORS, true)) {
                return [];
            }

            return [[
                'path' => $path,
                'problem' => 'unknown_operator',
                'operator' => is_scalar($operator) ? LoggableCode::guard((string) $operator) : null,
                'field' => is_scalar($field) ? LoggableCode::guard((string) $field) : null,
                'evaluates_as' => is_scalar($operator) && is_scalar($field) ? false : 'error_exception',
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
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, false),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            default => false,
        };
    }
}
