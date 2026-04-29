<?php

namespace App\Domains\Notifications\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sending = 'sending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Bounced, self::Cancelled], true);
    }
}
