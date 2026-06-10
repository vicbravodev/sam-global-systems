<?php

namespace App\Domains\Context\Enums;

enum GeofenceCategory: string
{
    case ClientSite = 'client_site';
    case RiskZone = 'risk_zone';
    case Border = 'border';
    case DistributionCenter = 'distribution_center';
    case RestrictedRoute = 'restricted_route';
    case Base = 'base';

    public function isSensitive(): bool
    {
        return match ($this) {
            self::RiskZone, self::Border => true,
            default => false,
        };
    }

    /**
     * Geofences that count as the fleet's own operational base — a vehicle
     * parked inside one is "at home", which weakens panic-style alerts
     * (Roadmap B6-P7 false-alarm validation).
     */
    public function isBase(): bool
    {
        return match ($this) {
            self::Base, self::DistributionCenter => true,
            default => false,
        };
    }
}
