<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Jobs\CreateIncidentJob;

/**
 * Opens an incident for every decision an operator must see.
 *
 * INCIDENT / ESCALATE keep the decision priority. REQUIRE_HUMAN_REVIEW and
 * ALERT used to die silently (nothing surfaced them to operators): now a
 * review opens a medium-SLA incident flagged for review (it lands in the
 * inbox in the in-review/"Triage" status) and an ALERT opens a low-priority
 * incident. IGNORE / LOG_ONLY create nothing.
 */
class CreateIncidentOnDecisionMade
{
    public const string REVIEW_REASON = 'La decisión automática requiere revisión humana antes de actuar.';

    public function handle(DecisionMade $event): void
    {
        $decision = $event->decision;
        $outcome = DecisionOutcomeCode::tryFrom(strtoupper((string) $decision->outcome?->code));

        if ($outcome === null || ! $outcome->createsIncident()) {
            return;
        }

        if ($decision->normalized_event_id === null) {
            return;
        }

        $context = match ($outcome) {
            DecisionOutcomeCode::RequireHumanReview => [
                'decision_id' => $decision->id,
                'priority_code' => IncidentPriorityCode::Medium->value,
                'request_review' => self::REVIEW_REASON,
                'metadata' => [
                    'decision_outcome' => $outcome->value,
                    'requires_review' => true,
                ],
            ],
            DecisionOutcomeCode::Alert => [
                'decision_id' => $decision->id,
                'priority_code' => IncidentPriorityCode::Low->value,
                'metadata' => [
                    'decision_outcome' => $outcome->value,
                ],
            ],
            default => array_filter([
                'decision_id' => $decision->id,
                'priority_code' => $decision->priority_level?->value,
            ], static fn ($v): bool => $v !== null),
        };

        CreateIncidentJob::dispatch((int) $decision->normalized_event_id, $context)
            ->afterCommit();
    }
}
