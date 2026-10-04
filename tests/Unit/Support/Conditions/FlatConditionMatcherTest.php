<?php

namespace Tests\Unit\Support\Conditions;

use App\Support\Conditions\FlatConditionMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FlatConditionMatcherTest extends TestCase
{
    /**
     * Payload con la forma del webhook `AlertIncident` de Samsara: dos
     * condiciones, el pánico en la segunda.
     *
     * @return array<string, mixed>
     */
    private function alertIncident(): array
    {
        return [
            'eventType' => 'AlertIncident',
            'data' => [
                'conditions' => [
                    ['triggerId' => 1045, 'description' => 'Tampering Detected'],
                    ['triggerId' => 1034, 'description' => 'Panic Button'],
                ],
            ],
        ];
    }

    public function test_empty_conditions_always_match(): void
    {
        $matcher = new FlatConditionMatcher;

        $this->assertNull($matcher->firstFailedPath([], $this->alertIncident()));
        $this->assertNull($matcher->firstFailedPath([], null));
    }

    public function test_conditions_without_payload_fail_with_star(): void
    {
        $this->assertSame('*', (new FlatConditionMatcher)->firstFailedPath(['eventType' => 'AlertIncident'], null));
    }

    public function test_plain_path_compares_the_value_at_that_path(): void
    {
        $matcher = new FlatConditionMatcher;

        $this->assertNull($matcher->firstFailedPath(['data.conditions.1.description' => 'Panic Button'], $this->alertIncident()));
        $this->assertSame(
            'data.conditions.0.description',
            $matcher->firstFailedPath(['data.conditions.0.description' => 'Panic Button'], $this->alertIncident()),
        );
    }

    public function test_wildcard_matches_when_any_element_equals(): void
    {
        $matcher = new FlatConditionMatcher;

        $this->assertNull($matcher->firstFailedPath(['data.conditions.*.triggerId' => 1034], $this->alertIncident()));
        $this->assertNull($matcher->firstFailedPath(['data.conditions.*.triggerId' => 1045], $this->alertIncident()));
        $this->assertSame(
            'data.conditions.*.triggerId',
            $matcher->firstFailedPath(['data.conditions.*.triggerId' => 5016], $this->alertIncident()),
        );
    }

    public function test_nested_wildcards_reach_any_detail(): void
    {
        $payload = ['data' => ['conditions' => [
            ['details' => ['panicButton' => ['vehicle' => ['id' => '281']]]],
            ['details' => ['tamperingDetected' => ['vehicle' => ['id' => '494']]]],
        ]]];
        $matcher = new FlatConditionMatcher;

        $this->assertNull($matcher->firstFailedPath(['data.conditions.*.details.*.vehicle.id' => '494'], $payload));
        // Un elemento que es un objeto no se compara con un escalar.
        $this->assertNotNull($matcher->firstFailedPath(['data.conditions.*.details' => '494'], $payload));
    }

    public function test_wildcard_over_missing_or_empty_list_fails(): void
    {
        $matcher = new FlatConditionMatcher;

        $this->assertSame('data.conditions.*.triggerId', $matcher->firstFailedPath(
            ['data.conditions.*.triggerId' => 1034],
            ['data' => ['conditions' => []]],
        ));
        $this->assertSame('data.conditions.*.triggerId', $matcher->firstFailedPath(
            ['data.conditions.*.triggerId' => 1034],
            ['eventType' => 'AlertIncident'],
        ));
    }

    public function test_all_conditions_must_hold(): void
    {
        $matcher = new FlatConditionMatcher;

        $this->assertNull($matcher->firstFailedPath(
            ['eventType' => 'AlertIncident', 'data.conditions.*.triggerId' => 1034],
            $this->alertIncident(),
        ));
        $this->assertSame('eventType', $matcher->firstFailedPath(
            ['eventType' => 'SafetyEvent', 'data.conditions.*.triggerId' => 1034],
            $this->alertIncident(),
        ));
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function scalarComparisons(): array
    {
        return [
            'int vs numeric string' => [1034, '1034', true],
            'numeric string vs int' => ['1034', 1034, true],
            'float vs string' => [1.5, '1.5', true],
            'different numbers' => [1034, '1035', false],
            'same string' => ['Panic Button', 'Panic Button', true],
            'case differs' => ['panic button', 'Panic Button', false],
            'bool vs bool' => [true, true, true],
            'bool vs string' => [true, 'true', false],
            'bool vs int' => [true, 1, false],
            'null vs null' => [null, null, true],
            'null vs empty string' => [null, '', false],
            'array never equals scalar' => [['x'], 'x', false],
        ];
    }

    #[DataProvider('scalarComparisons')]
    public function test_scalars_compare_by_their_text_and_bools_and_null_strictly(mixed $actual, mixed $expected, bool $matches): void
    {
        $failed = (new FlatConditionMatcher)->firstFailedPath(['value' => $expected], ['value' => $actual]);

        $this->assertSame($matches, $failed === null);
    }
}
