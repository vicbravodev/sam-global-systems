<?php

namespace Tests\Unit\Domains\Decisions\Support;

use App\Domains\Decisions\Support\RuleConditionEvaluator;
use PHPUnit\Framework\TestCase;

class RuleConditionEvaluatorTest extends TestCase
{
    private RuleConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleConditionEvaluator;
    }

    public function test_empty_conditions_match_anything(): void
    {
        $this->assertTrue($this->evaluator->matches([], ['classification' => 'real_event']));
    }

    public function test_all_block_requires_every_child_to_match(): void
    {
        $facts = ['classification' => 'real_event', 'risk_score' => 0.9];

        $matches = $this->evaluator->matches([
            'all' => [
                ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.8],
            ],
        ], $facts);

        $this->assertTrue($matches);
    }

    public function test_all_block_fails_when_any_child_misses(): void
    {
        $facts = ['classification' => 'real_event', 'risk_score' => 0.5];

        $matches = $this->evaluator->matches([
            'all' => [
                ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.8],
            ],
        ], $facts);

        $this->assertFalse($matches);
    }

    public function test_any_block_passes_when_one_child_matches(): void
    {
        $matches = $this->evaluator->matches([
            'any' => [
                ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'collision'],
                ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
            ],
        ], ['event_type_code' => 'panic_button']);

        $this->assertTrue($matches);
    }

    public function test_in_and_not_in_operators(): void
    {
        $this->assertTrue($this->evaluator->matches(
            ['field' => 'event_type_code', 'operator' => 'in', 'value' => ['collision', 'panic_button']],
            ['event_type_code' => 'collision'],
        ));

        $this->assertFalse($this->evaluator->matches(
            ['field' => 'event_type_code', 'operator' => 'not_in', 'value' => ['collision']],
            ['event_type_code' => 'collision'],
        ));
    }

    public function test_is_null_operator(): void
    {
        $this->assertTrue($this->evaluator->matches(
            ['field' => 'driver_id', 'operator' => 'is_null'],
            ['driver_id' => null],
        ));
        $this->assertFalse($this->evaluator->matches(
            ['field' => 'driver_id', 'operator' => 'is_null'],
            ['driver_id' => 7],
        ));
    }

    public function test_unknown_operator_returns_false(): void
    {
        $this->assertFalse($this->evaluator->matches(
            ['field' => 'risk_score', 'operator' => 'wat', 'value' => 0.5],
            ['risk_score' => 0.9],
        ));
    }
}
