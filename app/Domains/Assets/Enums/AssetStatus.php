<?php

namespace App\Domains\Assets\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Offline = 'offline';
    case Alert = 'alert';
    case Critical = 'critical';
    case Maintenance = 'maintenance';
}
