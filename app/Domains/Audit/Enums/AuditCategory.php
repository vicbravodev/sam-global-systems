<?php

namespace App\Domains\Audit\Enums;

enum AuditCategory: string
{
    case Domain = 'domain';
    case Security = 'security';
    case Billing = 'billing';
    case System = 'system';
    case Ai = 'ai';
    case Integration = 'integration';
}
