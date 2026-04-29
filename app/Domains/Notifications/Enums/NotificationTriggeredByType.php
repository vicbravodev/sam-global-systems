<?php

namespace App\Domains\Notifications\Enums;

enum NotificationTriggeredByType: string
{
    case System = 'system';
    case User = 'user';
    case Automation = 'automation';
}
