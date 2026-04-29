<?php

namespace App\Domains\TenantConfig\Enums;

enum RiskTolerance: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
