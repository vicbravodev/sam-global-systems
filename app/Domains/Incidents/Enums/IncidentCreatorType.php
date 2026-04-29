<?php

namespace App\Domains\Incidents\Enums;

enum IncidentCreatorType: string
{
    case System = 'system';
    case User = 'user';
}
