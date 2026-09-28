<?php

namespace App\Domains\Tenancy\Enums;

enum BillingModel: string
{
    case IncludedOnly = 'included_only';
    case Metered = 'metered';
    case Tiered = 'tiered';
    case FlatPlusOverage = 'flat_plus_overage';

    /**
     * Provider cost passed through with a margin: the meter accumulates the
     * real provider cost in micro-units (e.g. Twilio USD micros) and the
     * invoice line is cost × (1 + markup_percent / 100). No included quota.
     */
    case CostPlus = 'cost_plus';

    public function label(): string
    {
        return match ($this) {
            self::IncludedOnly => 'Solo incluido',
            self::Metered => 'Por consumo',
            self::Tiered => 'Por niveles',
            self::FlatPlusOverage => 'Fijo + excedente',
            self::CostPlus => 'Costo + margen',
        };
    }
}
