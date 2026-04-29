<?php

namespace App\Domains\Notifications\Enums;

enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case PartiallySent = 'partially_sent';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
