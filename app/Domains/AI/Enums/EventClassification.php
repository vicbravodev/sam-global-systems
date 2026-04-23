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
}
