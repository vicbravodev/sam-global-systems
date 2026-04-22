<?php

namespace App\Domains\Integrations\Enums;

enum TenantIntegrationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Error = 'error';
    case Pending = 'pending';
}
