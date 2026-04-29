<?php

namespace App\Domains\Audit\Enums;

enum ChangeActorType: string
{
    case User = 'user';
    case System = 'system';
    case Ai = 'ai';
    case Automation = 'automation';
}
