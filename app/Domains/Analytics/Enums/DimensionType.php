<?php

namespace App\Domains\Analytics\Enums;

enum DimensionType: string
{
    case Tenant = 'tenant';
    case Asset = 'asset';
    case Driver = 'driver';
    case Operator = 'operator';
    case Zone = 'zone';
    case Geofence = 'geofence';
    case EventType = 'event_type';
    case IncidentType = 'incident_type';
}
