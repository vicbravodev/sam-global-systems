<?php

namespace App\Domains\Assets\Enums;

enum AssetCategory: string
{
    case Vehicle = 'vehicle';
    case Trailer = 'trailer';
    case Camera = 'camera';
    case GpsDevice = 'gps_device';
    case Sensor = 'sensor';

    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Vehículo',
            self::Trailer => 'Remolque',
            self::Camera => 'Cámara',
            self::GpsDevice => 'Dispositivo GPS',
            self::Sensor => 'Sensor',
        };
    }
}
