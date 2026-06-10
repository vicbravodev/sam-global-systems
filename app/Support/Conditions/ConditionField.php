<?php

namespace App\Support\Conditions;

/**
 * Describes one selectable fact/field for the visual condition builders.
 * Labels are end-user copy and therefore Spanish.
 */
class ConditionField
{
    /**
     * @param  'string'|'number'|'boolean'|'enum'  $type
     * @param  array<int, array{value: string, label: string}>  $options  Only for enum fields.
     * @param  array<int, string>  $operators  Subset of RuleConditionEvaluator::OPERATORS.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly array $options = [],
        public readonly array $operators = [],
    ) {}

    /**
     * Default operator set for a field type.
     *
     * @return array<int, string>
     */
    public static function defaultOperators(string $type): array
    {
        return match ($type) {
            'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'is_null', 'is_not_null'],
            'boolean' => ['eq', 'neq'],
            'enum' => ['eq', 'neq', 'in', 'not_in', 'is_null', 'is_not_null'],
            default => ['eq', 'neq', 'in', 'not_in', 'contains', 'is_null', 'is_not_null'],
        };
    }

    /**
     * @return array{key: string, label: string, type: string, options: array<int, array{value: string, label: string}>, operators: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options,
            'operators' => $this->operators !== [] ? $this->operators : self::defaultOperators($this->type),
        ];
    }
}
