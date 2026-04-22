<?php

namespace App\Domains\Tenancy\Enums;

enum AggregationType: string
{
    case Sum = 'sum';
    case Max = 'max';
    case UniqueCount = 'unique_count';

    public function label(): string
    {
        return match ($this) {
            self::Sum => 'Sum',
            self::Max => 'Max',
            self::UniqueCount => 'Unique Count',
        };
    }
}
