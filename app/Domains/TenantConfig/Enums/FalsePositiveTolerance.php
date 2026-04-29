<?php

namespace App\Domains\TenantConfig\Enums;

enum FalsePositiveTolerance: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
