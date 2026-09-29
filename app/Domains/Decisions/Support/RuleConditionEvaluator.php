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
     * Reports the nodes that `matches()` silently evaluates to false because
     * they are malformed or use an unknown operator. Pure: walks the tree
     * with the same semantics as `matches()` and never logs condition values.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<array{path: string, problem: string, operator: ?string, field: ?string}>
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
                    $childPath = "{$path}.{$block}.{$i}";

                    if (! is_array($child)) {
                        $problems[] = ['path' => $childPath, 'problem' => 'malformed_condition', 'operator' => null, 'field' => null];

                        continue;
                    }

                    array_push($problems, ...$this->problems($child, $childPath));
                }

                return $problems;
            }
        }

        if (isset($conditions['field'], $conditions['operator'])) {
            if (in_array((string) $conditions['operator'], self::OPERATORS, true)) {
                return [];
            }

            return [[
                'path' => $path,
                'problem' => 'unknown_operator',
                'operator' => LoggableCode::guard((string) $conditions['operator']),
                'field' => LoggableCode::guard((string) $conditions['field']),
            ]];
        }

        return [['path' => $path, 'problem' => 'malformed_condition', 'operator' => null, 'field' => null]];
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
