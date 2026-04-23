<?php

namespace App\Domains\AI\Enums;

enum ReevaluationTrigger: string
{
    case MediaArrived = 'media_arrived';
    case ContextUpdated = 'context_updated';
    case ManualReviewRequested = 'manual_review_requested';
    case IncidentLinked = 'incident_linked';
    case RuleChanged = 'rule_changed';
}
