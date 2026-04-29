<?php

namespace App\Domains\Analytics\Enums;

enum ReportExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
