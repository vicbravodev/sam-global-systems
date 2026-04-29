<?php

namespace App\Domains\Audit\Enums;

enum TraceRelationType: string
{
    case CausedBy = 'caused_by';
    case Generated = 'generated';
    case Triggered = 'triggered';
    case LinkedTo = 'linked_to';
    case EscalatedFrom = 'escalated_from';
    case ReevaluatedFrom = 'reevaluated_from';
}
