<?php

namespace App\Domains\Context\Enums;

enum MediaRequestStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isInFlight(): bool
    {
        return match ($this) {
            self::Pending, self::Sent, self::Processing => true,
            default => false,
        };
    }
}
