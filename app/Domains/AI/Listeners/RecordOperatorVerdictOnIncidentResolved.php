<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Actions\RecordOperatorVerdict;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Events\IncidentResolved;

/**
 * "Descartar como falso positivo" en la bandeja cierra el incidente con
 * `resolution_code = false_positive`. Esa decisión humana es la etiqueta
 * que la IA necesita: se registra como veredicto del operador sobre la
 * evaluación más reciente del evento de origen.
 *
 * Sólo cuenta cuando lo resuelve una persona: los cierres automáticos
 * (sistema/proveedor) no son una etiqueta humana.
 */
class RecordOperatorVerdictOnIncidentResolved
{
    public function __construct(
        private readonly RecordOperatorVerdict $recordOperatorVerdict,
    ) {}

    public function handle(IncidentResolved $event): void
    {
        $resolution = $event->resolution;
        $incident = $event->incident;

        if ($resolution->resolution_code !== ResolutionCode::FalsePositive
            || $resolution->resolved_by_type !== IncidentCreatorType::User
            || $incident->related_event_id === null) {
            return;
        }

        $this->recordOperatorVerdict->execute(
            teamId: $incident->team_id,
            normalizedEventId: $incident->related_event_id,
            verdict: OperatorVerdict::FalsePositive,
            userId: $resolution->resolved_by_id,
            note: $resolution->resolution_summary,
        );
    }
}
