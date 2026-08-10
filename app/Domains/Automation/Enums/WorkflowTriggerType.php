<?php

namespace App\Domains\Automation\Enums;

use App\Contracts\HasLabel;

enum WorkflowTriggerType: string implements HasLabel
{
    case DecisionOutcome = 'decision_outcome';
    case IncidentCreated = 'incident_created';
    case IncidentEscalated = 'incident_escalated';
    case PriorityChanged = 'priority_changed';
    case MediaArrived = 'media_arrived';
    case ManualTrigger = 'manual_trigger';

    public function label(): string
    {
        return match ($this) {
            self::DecisionOutcome => 'Resultado de decisión',
            self::IncidentCreated => 'Incidente creado',
            self::IncidentEscalated => 'Incidente escalado',
            self::PriorityChanged => 'Cambio de prioridad',
            self::MediaArrived => 'Media recibida',
            self::ManualTrigger => 'Disparo manual',
        };
    }
}
