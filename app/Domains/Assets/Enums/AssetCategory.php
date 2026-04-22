<?php

namespace App\Domains\Assets\Enums;

enum AssetCategory: string
{
    case Vehicle = 'vehicle';
    case Trailer = 'trailer';
    case Camera = 'camera';
    case GpsDevice = 'gps_device';
    case Sensor = 'sensor';
}
