<?php

namespace App\Domains\Drivers\Enums;

enum DriverStatus: string
{
    case Active = 'active';
    case OffDuty = 'off_duty';
    case Unavailable = 'unavailable';
    case Suspended = 'suspended';
    case UnderReview = 'under_review';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::OffDuty => 'Fuera de turno',
            self::Unavailable => 'No disponible',
            self::Suspended => 'Suspendido',
            self::UnderReview => 'En revisión',
        };
    }
}
