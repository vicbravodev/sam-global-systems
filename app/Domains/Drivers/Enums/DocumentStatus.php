<?php

namespace App\Domains\Drivers\Enums;

enum DocumentStatus: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case PendingRenewal = 'pending_renewal';
}
