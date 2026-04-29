<?php

namespace App\Domains\Incidents\Enums;

enum IncidentActionType: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
