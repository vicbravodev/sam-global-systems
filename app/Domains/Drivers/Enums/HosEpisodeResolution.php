<?php

namespace App\Domains\Drivers\Enums;

enum HosEpisodeResolution: string
{
    case Corrected = 'corrected';
    case Expired = 'expired';
    case Unenrolled = 'unenrolled';
}
