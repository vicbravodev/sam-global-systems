<?php

namespace App\Domains\AI\Enums;

enum EventClassification: string
{
    case RealEvent = 'real_event';
    case FalsePositive = 'false_positive';
    case Noise = 'noise';
    case Duplicate = 'duplicate';
    case Unclear = 'unclear';
    case PendingEvidence = 'pending_evidence';

    public function isActionable(): bool
    {
        return match ($this) {
            self::RealEvent, self::Unclear => true,
            default => false,
        };
    }
}
