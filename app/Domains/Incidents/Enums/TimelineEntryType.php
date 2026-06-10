<?php

namespace App\Domains\Incidents\Enums;

enum TimelineEntryType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case Assigned = 'assigned';
    case Escalated = 'escalated';
    case CommentAdded = 'comment_added';
    case EvidenceAdded = 'evidence_added';
    case ActionExecuted = 'action_executed';
    case Resolved = 'resolved';
    case ExternallyResolved = 'externally_resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Reclassified = 'reclassified';
    case EventLinked = 'event_linked';
}
