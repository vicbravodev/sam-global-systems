<?php

namespace App\Domains\Drivers\Enums;

enum AssignmentSource: string
{
    case Integration = 'integration';
    case Manual = 'manual';
}
