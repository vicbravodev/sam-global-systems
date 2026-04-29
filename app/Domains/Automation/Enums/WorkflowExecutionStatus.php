<?php

namespace App\Domains\Automation\Enums;

enum WorkflowExecutionStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
