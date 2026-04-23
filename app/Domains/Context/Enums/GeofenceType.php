<?php

namespace App\Domains\Context\Enums;

enum GeofenceType: string
{
    case Zone = 'zone';
    case Route = 'route';
    case Point = 'point';
}
