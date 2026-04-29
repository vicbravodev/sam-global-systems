<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Incidents\Jobs\CreateIncidentJob;
use Illuminate\Support\Facades\Log;

/**
 * SPEC-10-DEFERRED: Listener registered by string in IncidentsServiceProvider until the
 * Decisions domain (spec 10) is merged. The Decisions PR will provide
 * `App\Domains\Decisions\Events\DecisionMade` with public properties
 * `outcome`, `normalized_event_id`, `decision_id`, `priority_code`, and `incident_type_code`.
 *
 * Until the spec 10 PR lands, the listener is never triggered (the event class does not exist),
 * so Laravel does not blow up at boot. Once spec 10 merges this listener will start firing
 * automatically — no further wiring required.
 */
class CreateIncidentOnDecisionMade
{
    public function handle(object $event): void
    {
        $outcome = property_exists($event, 'outcome') ? (string) $event->outcome : null;

        if ($outcome === null || ! in_array(strtoupper($outcome), ['INCIDENT', 'ESCALATE'], true)) {
            return;
        }

        $normalizedEventId = property_exists($event, 'normalized_event_id') ? $event->normalized_event_id : null;

        if ($normalizedEventId === null && property_exists($event, 'normalizedEventId')) {
            $normalizedEventId = $event->normalizedEventId;
        }

        if ($normalizedEventId === null) {
            Log::warning('CreateIncidentOnDecisionMade: missing normalized_event_id on DecisionMade payload.', [
                'event' => $event::class,
            ]);

            return;
        }

        $context = [];

        foreach (['decision_id' => 'decisionId', 'priority_code' => 'priorityCode', 'incident_type_code' => 'incidentTypeCode'] as $snake => $camel) {
            $value = property_exists($event, $snake) ? $event->{$snake} : (property_exists($event, $camel) ? $event->{$camel} : null);
            if ($value !== null) {
                $context[$snake] = $value;
            }
        }

        CreateIncidentJob::dispatch((int) $normalizedEventId, $context)
            ->afterCommit();
    }
}
