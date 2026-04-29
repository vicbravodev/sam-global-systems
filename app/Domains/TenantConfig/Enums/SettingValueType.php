<?php

namespace App\Domains\TenantConfig\Enums;

enum SettingValueType: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Json = 'json';
    case Array = 'array';

    /**
     * Validate that the given raw value is compatible with this type.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value),
            self::Number => is_int($value) || is_float($value),
            self::Boolean => is_bool($value),
            self::Json => is_array($value) || is_object($value),
            self::Array => is_array($value),
        };
    }
}
