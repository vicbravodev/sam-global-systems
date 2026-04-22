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
            self::IncludedOnly => 'Included Only',
            self::Metered => 'Metered',
            self::Tiered => 'Tiered',
            self::FlatPlusOverage => 'Flat + Overage',
        };
    }
}
