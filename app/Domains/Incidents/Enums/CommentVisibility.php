<?php

namespace App\Domains\Incidents\Enums;

enum CommentVisibility: string
{
    case Internal = 'internal';
    case TenantVisible = 'tenant_visible';
    case AuditOnly = 'audit_only';
}
