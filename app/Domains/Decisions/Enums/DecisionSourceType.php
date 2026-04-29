<?php

namespace App\Domains\Decisions\Enums;

enum DecisionSourceType: string
{
    case Ai = 'ai';
    case Rule = 'rule';
    case TenantPolicy = 'tenant_policy';
    case EscalationPolicy = 'escalation_policy';
    case Fallback = 'fallback';
    case ManualOverride = 'manual_override';
}
