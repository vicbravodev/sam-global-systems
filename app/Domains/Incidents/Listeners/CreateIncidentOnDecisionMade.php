<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Jobs\CreateIncidentJob;

class CreateIncidentOnDecisionMade
{
    public function handle(DecisionMade $event): void
    {
        $decision = $event->decision;
        $outcomeCode = $decision->outcome?->code;

        if ($outcomeCode === null || ! in_array(strtoupper($outcomeCode), ['INCIDENT', 'ESCALATE'], true)) {
            return;
        }

        if ($decision->normalized_event_id === null) {
            return;
        }

        $context = array_filter([
            'decision_id' => $decision->id,
            'priority_code' => $decision->priority_level?->value,
        ], static fn ($v): bool => $v !== null);

        CreateIncidentJob::dispatch((int) $decision->normalized_event_id, $context)
            ->afterCommit();
    }
}
