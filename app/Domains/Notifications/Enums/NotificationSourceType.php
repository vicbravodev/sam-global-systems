<?php

namespace App\Domains\Notifications\Enums;

enum NotificationSourceType: string
{
    case Incident = 'incident';
    case Decision = 'decision';
    case ActionExecution = 'action_execution';
    case Escalation = 'escalation';
    case Manual = 'manual';
    case SystemEvent = 'system_event';
}
