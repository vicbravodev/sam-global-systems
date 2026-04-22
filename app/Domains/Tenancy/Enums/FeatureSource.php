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
            self::DefaultPlan => 'Default Plan',
            self::ManualOverride => 'Manual Override',
            self::Promo => 'Promo',
            self::BetaAccess => 'Beta Access',
        };
    }
}
