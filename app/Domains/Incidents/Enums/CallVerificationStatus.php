<?php

namespace App\Domains\Incidents\Enums;

enum CallVerificationStatus: string
{
    case Pending = 'pending';
    case Calling = 'calling';
    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Calling], true);
    }
}
