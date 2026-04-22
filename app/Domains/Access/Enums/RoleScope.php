<?php

namespace App\Domains\Access\Enums;

enum RoleScope: string
{
    case Global = 'global';
    case Tenant = 'tenant';
}
