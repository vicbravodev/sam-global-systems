<?php

namespace App\Domains\Decisions\Support;

class RuleConditionEvaluator
{
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
