<?php

namespace App\Domains\Context\Enums;

enum MediaAvailabilityStatus: string
{
    case Available = 'available';
    case Pending = 'pending';
    case NotAvailable = 'not_available';
    case Expired = 'expired';
}
