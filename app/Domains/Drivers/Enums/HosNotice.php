<?php

namespace App\Domains\Drivers\Enums;

/** Which reminder text a step of the HOS ladder sends to the driver. */
enum HosNotice: string
{
    case BreakLead = 'break_lead';
    case BreakLimit = 'break_limit';
    case BreakInsist = 'break_insist';
    case DriveLead = 'drive_lead';
    case DriveLimit = 'drive_limit';
    case DriveInsist = 'drive_insist';
    case ShiftLead = 'shift_lead';
    case ShiftLimit = 'shift_limit';
    case ShiftInsist = 'shift_insist';
    case CycleLead = 'cycle_lead';
    case RestComplete = 'rest_complete';
    case Violation = 'violation';

    /** Warning before a limit (30/15 min) or a cycle threshold. */
    public static function lead(HosSituation $situation): self
    {
        return match ($situation) {
            HosSituation::DriveLimit => self::DriveLead,
            HosSituation::ShiftLimit => self::ShiftLead,
            HosSituation::CycleLimit => self::CycleLead,
            HosSituation::RestComplete => self::RestComplete,
            HosSituation::Violation => self::Violation,
            HosSituation::BreakDue => self::BreakLead,
        };
    }

    /** Limit reached: the first step says so, the next ones insist. */
    public static function limit(HosSituation $situation, bool $insist): self
    {
        return match ($situation) {
            HosSituation::DriveLimit => $insist ? self::DriveInsist : self::DriveLimit,
            HosSituation::ShiftLimit => $insist ? self::ShiftInsist : self::ShiftLimit,
            HosSituation::CycleLimit => self::CycleLead,
            HosSituation::RestComplete => self::RestComplete,
            HosSituation::Violation => self::Violation,
            HosSituation::BreakDue => $insist ? self::BreakInsist : self::BreakLimit,
        };
    }
}
