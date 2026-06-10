<?php

namespace Tests\Unit\Support\Conditions;

use App\Support\Conditions\ValidFlatConditions;
use PHPUnit\Framework\TestCase;

class ValidFlatConditionsTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function errorsFor(mixed $value): array
    {
        $errors = [];

        (new ValidFlatConditions)->validate(
            'trigger_conditions_json',
            $value,
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        return $errors;
    }

    public function test_empty_map_is_valid(): void
    {
        $this->assertSame([], $this->errorsFor([]));
    }

    public function test_scalar_values_pass(): void
    {
        $this->assertSame([], $this->errorsFor([
            'severity' => 'critical',
            'data.alert.type' => 'panic_button',
            'retries' => 3,
            'is_active' => true,
            'optional' => null,
        ]));
    }

    public function test_non_array_fails(): void
    {
        $this->assertNotSame([], $this->errorsFor('severity=critical'));
    }

    public function test_sequential_list_fails(): void
    {
        $errors = $this->errorsFor(['critical', 'high']);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('no una lista', $errors[0]);
    }

    public function test_nested_array_value_fails(): void
    {
        $errors = $this->errorsFor(['severity' => ['critical', 'high']]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('valor simple', $errors[0]);
    }
}
