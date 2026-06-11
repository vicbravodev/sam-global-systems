<?php

namespace App\Domains\Decisions\Support;

/**
 * Walks a condition tree and reports the verdict of every leaf against a
 * fact map (expected vs. actual), so the rule tester can show exactly which
 * checks matched and which did not.
 */
class ConditionExplainer
{
    public function __construct(
        private readonly RuleConditionEvaluator $evaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $facts
     * @return array{matched: bool, checks: array<int, array{field: string, operator: string, expected: mixed, actual: mixed, passed: bool}>}
     */
    public function explain(array $conditions, array $facts): array
    {
        $checks = [];
        $this->collectChecks($conditions, $facts, $checks);

        return [
            'matched' => $this->evaluator->matches($conditions, $facts),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $facts
     * @param  array<int, array{field: string, operator: string, expected: mixed, actual: mixed, passed: bool}>  $checks
     */
    private function collectChecks(array $node, array $facts, array &$checks): void
    {
        foreach (['all', 'any'] as $logic) {
            if (isset($node[$logic]) && is_array($node[$logic])) {
                foreach ($node[$logic] as $child) {
                    if (is_array($child)) {
                        $this->collectChecks($child, $facts, $checks);
                    }
                }

                return;
            }
        }

        if (isset($node['field'], $node['operator'])) {
            $field = (string) $node['field'];

            $checks[] = [
                'field' => $field,
                'operator' => (string) $node['operator'],
                'expected' => $node['value'] ?? null,
                'actual' => $facts[$field] ?? null,
                'passed' => $this->evaluator->matches($node, $facts),
            ];
        }
    }
}
