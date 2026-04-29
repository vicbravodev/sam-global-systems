<?php

namespace App\Domains\Audit\Enums;

enum TraceStatus: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
}
