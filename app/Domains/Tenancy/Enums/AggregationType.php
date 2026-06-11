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
            self::Sum => 'Suma',
            self::Max => 'Máximo',
            self::UniqueCount => 'Conteo único',
        };
    }
}
