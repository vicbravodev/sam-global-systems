<?php

namespace App\Domains\Assets\Enums;

enum LocationSource: string
{
    case Provider = 'provider';
    case Gps = 'gps';
    case Manual = 'manual';
}
