<?php

namespace App\Domains\Tenancy\Enums;

enum ResetPeriod: string
{
    case Monthly = 'monthly';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
            self::Daily => 'Diario',
        };
    }
}
