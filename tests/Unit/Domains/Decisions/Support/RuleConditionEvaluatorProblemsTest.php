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

        $this->assertSame([
            ['path' => '$.any.0', 'problem' => 'unknown_operator', 'operator' => 'between', 'field' => 'risk_score'],
            ['path' => '$.any.1', 'problem' => 'malformed_condition', 'operator' => null, 'field' => null],
        ], $problems);
        $this->assertStringNotContainsString('texto libre', json_encode($problems));
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
