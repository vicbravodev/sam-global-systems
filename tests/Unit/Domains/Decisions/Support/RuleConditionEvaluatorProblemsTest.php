<?php

namespace Tests\Unit\Domains\Decisions\Support;

use App\Domains\Decisions\Support\RuleConditionEvaluator;
use PHPUnit\Framework\TestCase;

class RuleConditionEvaluatorProblemsTest extends TestCase
{
    public function test_valid_and_empty_trees_have_no_problems(): void
    {
        $evaluator = new RuleConditionEvaluator;

        $this->assertSame([], $evaluator->problems([]));
        $this->assertSame([], $evaluator->problems([
            'any' => [['all' => [['field' => 'risk_score', 'operator' => 'gte', 'value' => 1]]]],
        ]));
    }

    public function test_reports_nested_unknown_operator_and_non_array_child(): void
    {
        $problems = (new RuleConditionEvaluator)->problems([
            'any' => [
                ['field' => 'risk_score', 'operator' => 'between', 'value' => 'texto libre'],
                'oops',
            ],
        ]);

        // Todo nodo inválido evalúa false: una regla mal configurada nunca casa.
        $this->assertSame([
            ['path' => '$.any.0', 'problem' => 'unknown_operator', 'operator' => 'between', 'field' => 'risk_score', 'evaluates_as' => false],
            ['path' => '$.any.1', 'problem' => 'non_array_node', 'operator' => null, 'field' => null, 'evaluates_as' => false],
        ], $problems);
        $this->assertStringNotContainsString('texto libre', json_encode($problems));
    }

    public function test_non_array_child_is_a_non_array_node_that_evaluates_as_false(): void
    {
        $evaluator = new RuleConditionEvaluator;

        $this->assertSame([
            ['path' => '$.all.0', 'problem' => 'non_array_node', 'operator' => null, 'field' => null, 'evaluates_as' => false],
        ], $evaluator->problems(['all' => ['oops']]));

        // No lanza: el nodo no casa, así que 'all' es false y 'any' sigue con el resto.
        $this->assertFalse($evaluator->matches(['all' => ['oops']], []));
        $this->assertFalse($evaluator->matches(['any' => ['oops']], []));
        $this->assertTrue($evaluator->matches(['any' => ['oops', ['field' => 'x', 'operator' => 'eq', 'value' => 1]]], ['x' => 1]));
        $this->assertFalse($evaluator->matches(['all' => [['field' => 'x', 'operator' => 'eq', 'value' => 1], 'oops']], ['x' => 1]));
    }

    public function test_malformed_and_unknown_operator_nodes_evaluate_as_false(): void
    {
        $evaluator = new RuleConditionEvaluator;

        $malformed = $evaluator->problems(['foo' => 'bar']);
        $this->assertSame('malformed_condition', $malformed[0]['problem']);
        $this->assertFalse($malformed[0]['evaluates_as']);
        $this->assertFalse($evaluator->matches(['foo' => 'bar'], []));

        $unknown = $evaluator->problems(['field' => 'risk_score', 'operator' => 'between']);
        $this->assertSame('unknown_operator', $unknown[0]['problem']);
        $this->assertFalse($unknown[0]['evaluates_as']);
        $this->assertFalse($evaluator->matches(['field' => 'risk_score', 'operator' => 'between'], ['risk_score' => 1]));
    }

    public function test_non_scalar_operator_or_field_is_a_non_scalar_leaf_that_evaluates_as_false(): void
    {
        $evaluator = new RuleConditionEvaluator;

        $operator = $evaluator->problems(['field' => 'x', 'operator' => ['a']]);
        $this->assertSame('non_scalar_leaf', $operator[0]['problem']);
        $this->assertSame('x', $operator[0]['field']);
        $this->assertNull($operator[0]['operator']);
        $this->assertFalse($operator[0]['evaluates_as']);
        $this->assertFalse($evaluator->matches(['field' => 'x', 'operator' => ['a']], ['x' => 1]));

        // Operador válido con un campo que no es escalar: antes no se reportaba y lanzaba.
        $field = $evaluator->problems(['field' => ['x'], 'operator' => 'eq', 'value' => 1]);
        $this->assertSame('non_scalar_leaf', $field[0]['problem']);
        $this->assertSame('eq', $field[0]['operator']);
        $this->assertNull($field[0]['field']);
        $this->assertFalse($field[0]['evaluates_as']);
        $this->assertFalse($evaluator->matches(['field' => ['x'], 'operator' => 'eq', 'value' => 1], ['x' => 1]));
    }

    public function test_free_text_operator_is_guarded(): void
    {
        $problems = (new RuleConditionEvaluator)->problems(['field' => 'x y', 'operator' => 'texto libre']);

        $this->assertNull($problems[0]['operator']);
        $this->assertNull($problems[0]['field']);
    }

    public function test_tenant_authored_block_keys_never_reach_the_path(): void
    {
        $problems = (new RuleConditionEvaluator)->problems([
            'all' => ['Texto libre del tenant' => ['foo' => 'bar'], 3 => ['field' => 'x', 'operator' => ['a']]],
        ]);

        $this->assertSame('$.all.?', $problems[0]['path']);
        $this->assertSame('$.all.3', $problems[1]['path']);
        $this->assertNull($problems[1]['operator']);
        $this->assertStringNotContainsString('Texto libre', json_encode($problems));
    }
}
