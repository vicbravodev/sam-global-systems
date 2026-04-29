<?php

namespace App\Domains\TenantConfig\Enums;

enum SettingGroup: string
{
    case Operational = 'operational';
    case Notification = 'notification';
    case Ai = 'ai';
    case Escalation = 'escalation';
    case Branding = 'branding';
    case Schedule = 'schedule';
    case Compliance = 'compliance';
}
