<?php

namespace App\Domains\Incidents\Enums;

enum AssigneeType: string
{
    case User = 'user';
    case Team = 'team';
    case Queue = 'queue';
    case AutomatedHandler = 'automated_handler';
}
