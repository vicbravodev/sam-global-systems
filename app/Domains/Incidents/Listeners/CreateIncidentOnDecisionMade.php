<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

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

        $decisionId = (int) $decision->id;

        if ($outcome === null) {
            $rawOutcomeCode = LoggableCode::guard($decision->outcome?->code);
            DB::afterCommit(fn () => SystemLog::skipped('incidents.creation.skipped', reason: 'unknown_outcome', input: ['decision_id' => $decisionId], calc: ['outcome_code' => $rawOutcomeCode]));

            return;
        }

        if (! $outcome->surfacesToOperators()) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.creation.skipped', reason: 'outcome_not_surfaced', input: ['decision_id' => $decisionId], calc: ['outcome_code' => $outcome->value]));

            return;
        }

        if ($decision->normalized_event_id === null) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.creation.skipped', reason: 'no_normalized_event', input: ['decision_id' => $decisionId], calc: ['outcome_code' => $outcome->value]));

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

        $normalizedEventId = (int) $decision->normalized_event_id;
        $priorityCode = LoggableCode::guard($context['priority_code'] ?? null);
        $prioritySource = match ($outcome) {
            DecisionOutcomeCode::RequireHumanReview => 'review_default_medium',
            DecisionOutcomeCode::Alert => 'alert_default_low',
            // Sin priority_level el contexto no lleva priority_code y la
            // apertura usa su propio default: no es "la de la decisión".
            default => isset($context['priority_code']) ? 'decision_priority' : 'decision_priority_missing',
        };
        $requestReview = isset($context['request_review']);

        DB::afterCommit(fn () => SystemLog::ok('incidents.creation.requested',
            input: ['decision_id' => $decisionId, 'normalized_event_id' => $normalizedEventId],
            calc: ['outcome_code' => $outcome->value, 'priority_code' => $priorityCode, 'priority_source' => $prioritySource, 'request_review' => $requestReview],
            result: ['job_requested' => true],
        ));
    }
}
