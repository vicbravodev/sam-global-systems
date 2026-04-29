<?php

namespace App\Domains\Incidents\Enums;

enum TimelineActorType: string
{
    case System = 'system';
    case User = 'user';
    case Ai = 'ai';
    case Automation = 'automation';
}
