<?php

namespace App\Domains\Context\Enums;

enum GeofenceCategory: string
{
    case ClientSite = 'client_site';
    case RiskZone = 'risk_zone';
    case Border = 'border';
    case DistributionCenter = 'distribution_center';
    case RestrictedRoute = 'restricted_route';

    public function isSensitive(): bool
    {
        return match ($this) {
            self::RiskZone, self::Border => true,
            default => false,
        };
    }
}
