<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Support\IncidentSuppression;

class TriggerAutomationOnIncidentEscalated
{
    public function __construct(
        private TriggerEscalationWorkflow $triggerEscalationWorkflow,
    ) {}

    public function handle(IncidentStatusChanged $event): void
    {
        $incident = $event->incident;

        // Sólo la escalación real dispara el workflow `incident_escalated`.
        // Antes corría en cualquier cambio de estado (in_review recién creado,
        // cierres, falsos positivos) y el dedup por (workflow, incidente) se
        // "gastaba" ahí, silenciando después la escalación de verdad.
        if ($event->newStatus !== IncidentStatusCode::Escalated->value) {
            return;
        }

        if ($incident->team_id === null) {
            return;
        }

        // Somebody already claimed it or acknowledged it: a human is on it,
        // the automation stays quiet.
        if (IncidentSuppression::isUnderHumanControl($incident)) {
            return;
        }

        $this->triggerEscalationWorkflow->execute(
            teamId: (int) $incident->team_id,
            triggerType: WorkflowTriggerType::IncidentEscalated,
            sourceType: ActionExecutionSourceType::Escalation,
            sourceReferenceId: (string) $incident->id,
            payload: [
                'incident_id' => $incident->id,
                'previous_status' => $event->previousStatus,
                'new_status' => $event->newStatus,
            ],
        );
    }
}
