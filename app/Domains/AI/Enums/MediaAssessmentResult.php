<?php

namespace App\Domains\AI\Enums;

enum MediaAssessmentResult: string
{
    case ConfirmsEvent = 'confirms_event';
    case ContradictsEvent = 'contradicts_event';
    case Inconclusive = 'inconclusive';
    case LowQuality = 'low_quality';
    case Unavailable = 'unavailable';

    public function isDecisive(): bool
    {
        return $this === self::ConfirmsEvent || $this === self::ContradictsEvent;
    }
}
