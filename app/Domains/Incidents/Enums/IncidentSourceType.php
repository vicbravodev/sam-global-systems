<?php

namespace App\Domains\Incidents\Enums;

enum IncidentSourceType: string
{
    case AiDecision = 'ai_decision';
    case NormalizedEvent = 'normalized_event';
    case RawEvent = 'raw_event';
    case Manual = 'manual';
    case EscalationPolicy = 'escalation_policy';
    case SystemRule = 'system_rule';
}
