<?php

namespace App\Domains\Audit\Enums;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Ai = 'ai';
    case Job = 'job';
    case WebhookSource = 'webhook_source';
    case Automation = 'automation';
}
