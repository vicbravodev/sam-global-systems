<?php

namespace App\Domains\Assets\Enums;

enum DeviceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Detached = 'detached';
}
