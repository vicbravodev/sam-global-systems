<?php

namespace App\Domains\Incidents\Enums;

enum ResolutionCode: string
{
    case HandledSuccessfully = 'handled_successfully';
    case FalsePositive = 'false_positive';
    case OperatorConfirmedSafe = 'operator_confirmed_safe';
    case ResolvedExternally = 'resolved_externally';
    case EscalatedExternally = 'escalated_externally';
    case UnresolvedClosed = 'unresolved_closed';
    case DuplicateIncident = 'duplicate_incident';
}
