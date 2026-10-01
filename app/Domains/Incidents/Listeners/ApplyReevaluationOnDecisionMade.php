<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Actions\ApplyReevaluationToIncident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Toda decisión nueva sobre un evento que ya tiene incidente (típicamente una
 * reevaluación v2+) se refleja en ESE incidente, sea cual sea su outcome: así
 * una reevaluación que ahora dice "falso positivo" (outcome sin incidente)
 * también deja el aviso al operador. La creación de incidentes sigue en
 * `CreateIncidentOnDecisionMade`.
 */
class ApplyReevaluationOnDecisionMade
{
    public function __construct(
        private readonly ApplyReevaluationToIncident $applyReevaluation,
    ) {}

    public function handle(DecisionMade $event): void
    {
        $decision = $event->decision;

        // NOT NULL en la tabla, pero DecisionMade puede llevar una decisión
        // sólo en memoria (ver CreateIncidentOnDecisionMade): atributo crudo.
        if ($decision->getAttribute('normalized_event_id') === null) {
            return;
        }

        TenantContext::for($decision->team_id, function () use ($decision) {
            $normalizedEvent = NormalizedEvent::query()
                ->where('team_id', $decision->team_id)
                ->find($decision->normalized_event_id);

            if ($normalizedEvent === null) {
                $decisionId = $decision->id;
                DB::afterCommit(fn () => SystemLog::skipped('incidents.reevaluation.applied', reason: 'event_missing', input: ['decision_id' => $decisionId]));

                return;
            }

            $incident = $this->applyReevaluation->findExistingFor($normalizedEvent);

            if ($incident !== null) {
                $this->applyReevaluation->execute($incident, $decision);
            }
        });
    }
}
