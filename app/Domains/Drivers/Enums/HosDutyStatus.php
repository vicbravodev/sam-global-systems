<?php

namespace App\Domains\Drivers\Enums;

/** Samsara `currentDutyStatus.hosStatusType` values (raw strings kept as backing values). */
enum HosDutyStatus: string
{
    case OffDuty = 'offDuty';
    case SleeperBed = 'sleeperBed';
    case Driving = 'driving';
    case OnDuty = 'onDuty';
    case YardMove = 'yardMove';
    case PersonalConveyance = 'personalConveyance';

    /** On the clock for the 14-hour window: driving or working without driving. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Driving, self::OnDuty, self::YardMove], true);
    }
}
