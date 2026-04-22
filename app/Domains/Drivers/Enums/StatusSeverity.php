<?php

namespace App\Domains\Drivers\Enums;

enum StatusSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
