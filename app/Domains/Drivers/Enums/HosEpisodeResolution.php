<?php

namespace App\Domains\Drivers\Enums;

enum HosEpisodeResolution: string
{
    case Corrected = 'corrected';
    case Expired = 'expired';
    case Incident = 'incident';
    case Unenrolled = 'unenrolled';
}
