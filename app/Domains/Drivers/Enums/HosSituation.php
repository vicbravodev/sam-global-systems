<?php

namespace App\Domains\Drivers\Enums;

enum HosSituation: string
{
    case BreakDue = 'break_due';
    case DriveLimit = 'drive_limit';
    case ShiftLimit = 'shift_limit';
    case CycleLimit = 'cycle_limit';
    case RestComplete = 'rest_complete';
    case Violation = 'violation';
}
