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

    /**
     * Las reglas del tenant se escribieron contra `==`/`in_array` laxos: la
     * igualdad explícita del evaluador debe dar exactamente lo mismo para
     * todo par hecho/valor posible (hechos null|bool|int|float|string; valores
     * del JSON de la regla, incluidos '5', 'true', '0', '' y ' 5').
     */
    public function test_equality_operators_preserve_php_loose_comparison(): void
    {
        $values = [
            null, true, false,
            0, 1, 5, -1,
            0.0, 0.5, 5.0, 0.95,
            '', '0', '1', '5', '5.0', ' 5', '5 ', '1e1', '10', '0.5', '00', 'abc', 'true', 'false', 'null',
            'real_event', 'panic_button', 'confirmed_false',
            [], ['5'], [5],
        ];

        foreach ($values as $actual) {
            foreach ($values as $expected) {
                $label = var_export($actual, true).' vs '.var_export($expected, true);
                $facts = ['f' => $actual];

                $this->assertSame(
                    $actual == $expected,
                    $this->evaluator->matches(['field' => 'f', 'operator' => 'eq', 'value' => $expected], $facts),
                    "eq {$label}",
                );
                $this->assertSame(
                    $actual != $expected,
                    $this->evaluator->matches(['field' => 'f', 'operator' => 'neq', 'value' => $expected], $facts),
                    "neq {$label}",
                );
                $this->assertSame(
                    in_array($actual, [$expected, 'zzz'], false),
                    $this->evaluator->matches(['field' => 'f', 'operator' => 'in', 'value' => [$expected, 'zzz']], $facts),
                    "in {$label}",
                );
                $this->assertSame(
                    ! in_array($actual, [$expected], false),
                    $this->evaluator->matches(['field' => 'f', 'operator' => 'not_in', 'value' => [$expected]], $facts),
                    "not_in {$label}",
                );
            }
        }
    }

    public function test_equality_keeps_numeric_string_and_boolean_semantics(): void
    {
        $this->assertTrue($this->evaluator->matches(
            ['field' => 'repeated_panic_count_24h', 'operator' => 'eq', 'value' => '3'],
            ['repeated_panic_count_24h' => 3],
        ));
        $this->assertTrue($this->evaluator->matches(
            ['field' => 'risk_score', 'operator' => 'in', 'value' => ['0.5', 1]],
            ['risk_score' => 0.5],
        ));
        // Hecho de cámara indeterminado (null) casa `eq false`, como con `==`.
        $this->assertTrue($this->evaluator->matches(
            ['field' => 'media_passenger_detected', 'operator' => 'eq', 'value' => false],
            ['media_passenger_detected' => null],
        ));
        $this->assertFalse($this->evaluator->matches(
            ['field' => 'classification', 'operator' => 'eq', 'value' => 0],
            ['classification' => 'real_event'],
        ));
    }
}
