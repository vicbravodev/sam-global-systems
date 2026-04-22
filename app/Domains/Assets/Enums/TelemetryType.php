<?php

namespace App\Domains\Assets\Enums;

enum TelemetryType: string
{
    case Speed = 'speed';
    case Fuel = 'fuel';
    case Temperature = 'temperature';
    case CameraStatus = 'camera_status';
    case Battery = 'battery';
    case Ignition = 'ignition';
    case Odometer = 'odometer';
}
