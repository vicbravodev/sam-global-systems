<?php

namespace App\Domains\Tenancy\Enums;

enum FeatureSource: string
{
    case DefaultPlan = 'default_plan';
    case ManualOverride = 'manual_override';
    case Promo = 'promo';
    case BetaAccess = 'beta_access';

    public function label(): string
    {
        return match ($this) {
            self::DefaultPlan => 'Plan por defecto',
            self::ManualOverride => 'Ajuste manual',
            self::Promo => 'Promoción',
            self::BetaAccess => 'Acceso beta',
        };
    }
}
