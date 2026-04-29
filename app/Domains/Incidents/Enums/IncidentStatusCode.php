<?php

namespace App\Domains\Incidents\Enums;

enum IncidentStatusCode: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case FalsePositive = 'false_positive';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Resolved, self::Closed, self::FalsePositive, self::Cancelled => true,
            default => false,
        };
    }
}
