<?php

namespace App\Domains\Drivers\Enums;

enum DriverStatus: string
{
    case Active = 'active';
    case OffDuty = 'off_duty';
    case Unavailable = 'unavailable';
    case Suspended = 'suspended';
    case UnderReview = 'under_review';
}
