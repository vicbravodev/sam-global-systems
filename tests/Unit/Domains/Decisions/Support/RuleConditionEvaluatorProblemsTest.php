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

        // Un hijo que no es array no evalúa false: matches() lanza TypeError al alcanzarlo.
        $this->assertSame([
            ['path' => '$.any.0', 'problem' => 'unknown_operator', 'operator' => 'between', 'field' => 'risk_score', 'evaluates_as' => false],
            ['path' => '$.any.1', 'problem' => 'non_array_node', 'operator' => null, 'field' => null, 'evaluates_as' => 'type_error'],
        ], $problems);
        $this->assertStringNotContainsString('texto libre', json_encode($problems));
    }

    public function test_non_array_child_is_a_non_array_node_that_raises_type_error(): void
    {
        $evaluator = new RuleConditionEvaluator;

        $this->assertSame([
            ['path' => '$.all.0', 'problem' => 'non_array_node', 'operator' => null, 'field' => null, 'evaluates_as' => 'type_error'],
        ], $evaluator->problems(['all' => ['oops']]));

        // La afirmación es cierta: matches() no lo evalúa false, lanza TypeError.
        $this->expectException(\TypeError::class);
        $evaluator->matches(['all' => ['oops']], []);
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

    public function test_non_scalar_operator_is_not_claimed_to_evaluate_as_false(): void
    {
        $problems = (new RuleConditionEvaluator)->problems(['field' => 'x', 'operator' => ['a']]);

        // matches() convierte el array a string: bajo el handler de Laravel ese
        // warning es una ErrorException, no un false silencioso.
        $this->assertSame('unknown_operator', $problems[0]['problem']);
        $this->assertSame('error_exception', $problems[0]['evaluates_as']);
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
