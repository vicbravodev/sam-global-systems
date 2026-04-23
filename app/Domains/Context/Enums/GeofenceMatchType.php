<?php

namespace App\Domains\Context\Enums;

enum GeofenceMatchType: string
{
    case Inside = 'inside';
    case Outside = 'outside';
    case Entry = 'entry';
    case Exit = 'exit';
    case NearBoundary = 'near_boundary';
}
