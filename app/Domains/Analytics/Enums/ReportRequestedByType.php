<?php

namespace App\Domains\Analytics\Enums;

enum ReportRequestedByType: string
{
    case User = 'user';
    case System = 'system';
    case Scheduler = 'scheduler';
}
