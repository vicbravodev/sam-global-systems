<?php

namespace App\Domains\TenantConfig\Enums;

enum SettingUpdatedByType: string
{
    case User = 'user';
    case System = 'system';
}
