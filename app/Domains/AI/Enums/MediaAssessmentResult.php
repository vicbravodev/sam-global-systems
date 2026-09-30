<?php

namespace App\Domains\AI\Enums;

enum MediaAssessmentResult: string
{
    case ConfirmsEvent = 'confirms_event';
    case ContradictsEvent = 'contradicts_event';
    case Inconclusive = 'inconclusive';
    case LowQuality = 'low_quality';
    case Unavailable = 'unavailable';

    /** Same wording as the UI's MEDIA_RESULT_LABEL (media-verdict.tsx). */
    public function label(): string
    {
        return match ($this) {
            self::ConfirmsEvent => 'Confirma el evento',
            self::ContradictsEvent => 'Contradice el evento',
            self::Inconclusive => 'No concluyente',
            self::LowQuality => 'Baja calidad',
            self::Unavailable => 'No disponible',
        };
    }

    public function isDecisive(): bool
    {
        return $this === self::ConfirmsEvent || $this === self::ContradictsEvent;
    }
}
