<?php

namespace App\Domains\Tenancy\Enums;

enum BillingModel: string
{
    case IncludedOnly = 'included_only';
    case Metered = 'metered';
    case Tiered = 'tiered';
    case FlatPlusOverage = 'flat_plus_overage';

    public function label(): string
    {
        return match ($this) {
            self::IncludedOnly => 'Solo incluido',
            self::Metered => 'Por consumo',
            self::Tiered => 'Por niveles',
            self::FlatPlusOverage => 'Fijo + excedente',
        };
    }
}
