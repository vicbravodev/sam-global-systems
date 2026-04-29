<?php

namespace App\Domains\Incidents\Enums;

enum IncidentPriorityCode: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
