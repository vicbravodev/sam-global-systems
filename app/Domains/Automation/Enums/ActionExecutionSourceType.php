<?php

namespace App\Domains\Automation\Enums;

enum ActionExecutionSourceType: string
{
    case Decision = 'decision';
    case Incident = 'incident';
    case Escalation = 'escalation';
    case Workflow = 'workflow';
    case Manual = 'manual';
}
