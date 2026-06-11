<?php

namespace Tests\Unit\Support\Conditions;

use App\Support\Conditions\ValidConditionTree;
use PHPUnit\Framework\TestCase;

class ValidConditionTreeTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function errorsFor(mixed $value): array
    {
        $errors = [];

        (new ValidConditionTree)->validate(
            'conditions_json',
            $value,
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        return $errors;
    }

    public function test_empty_tree_is_valid(): void
    {
        $this->assertSame([], $this->errorsFor([]));
    }

    public function test_valid_nested_tree_passes(): void
    {
        $tree = [
            'all' => [
                ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                [
                    'any' => [
                        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.8],
                        ['field' => 'priority_level', 'operator' => 'in', 'value' => ['high', 'urgent']],
                    ],
                ],
                ['field' => 'media_assessment', 'operator' => 'is_null'],
            ],
        ];

        $this->assertSame([], $this->errorsFor($tree));
    }

    public function test_non_array_value_fails(): void
    {
        $this->assertNotSame([], $this->errorsFor('not-an-array'));
    }

    public function test_unknown_operator_fails(): void
    {
        $errors = $this->errorsFor([
            'all' => [['field' => 'risk_score', 'operator' => 'between', 'value' => 0.5]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('operador no soportado', $errors[0]);
    }

    public function test_leaf_without_field_fails(): void
    {
        $errors = $this->errorsFor([
            'all' => [['operator' => 'eq', 'value' => 1]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('campo', $errors[0]);
    }

    public function test_comparison_operator_without_value_fails(): void
    {
        $errors = $this->errorsFor([
            'all' => [['field' => 'risk_score', 'operator' => 'gte']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('valor de comparación', $errors[0]);
    }

    public function test_in_operator_requires_a_list(): void
    {
        $errors = $this->errorsFor([
            'all' => [['field' => 'priority_level', 'operator' => 'in', 'value' => 'high']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('lista de valores', $errors[0]);
    }

    public function test_group_mixing_all_and_any_fails(): void
    {
        $errors = $this->errorsFor([
            'all' => [['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5]],
            'any' => [['field' => 'risk_score', 'operator' => 'lt', 'value' => 0.2]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('no puede combinar', $errors[0]);
    }

    public function test_empty_group_fails(): void
    {
        $this->assertNotSame([], $this->errorsFor(['all' => []]));
    }

    public function test_is_null_leaf_needs_no_value(): void
    {
        $this->assertSame([], $this->errorsFor([
            'any' => [['field' => 'media_assessment', 'operator' => 'is_not_null']],
        ]));
    }

    public function test_excessive_depth_fails(): void
    {
        $tree = ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5];

        for ($i = 0; $i < 12; $i++) {
            $tree = ['all' => [$tree]];
        }

        $errors = $this->errorsFor($tree);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('profundidad máxima', $errors[0]);
    }
}
