<?php

namespace App\Domains\AI\Enums;

enum RecommendedActionType: string
{
    case EscalateToOperator = 'escalate_to_operator';
    case CallDriver = 'call_driver';
    case IgnoreEvent = 'ignore_event';
    case CreateIncident = 'create_incident';
    case RequestVideoReview = 'request_video_review';
    case WaitForMedia = 'wait_for_media';
    case NotifySupervisor = 'notify_supervisor';
    case TriggerEmergencyProtocol = 'trigger_emergency_protocol';
}
