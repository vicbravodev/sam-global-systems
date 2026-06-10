<?php

namespace App\Domains\Tenancy\Enums;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
            self::Yearly => 'Anual',
        };
    }
}
