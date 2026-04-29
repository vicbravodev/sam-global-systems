<?php

namespace App\Domains\Automation\Enums;

enum WorkflowTriggerType: string
{
    case DecisionOutcome = 'decision_outcome';
    case IncidentCreated = 'incident_created';
    case IncidentEscalated = 'incident_escalated';
    case PriorityChanged = 'priority_changed';
    case MediaArrived = 'media_arrived';
    case ManualTrigger = 'manual_trigger';
}
