<?php

namespace Tests\Fakes;

/**
 * Stand-in for `App\Domains\Decisions\Events\DecisionMade` until spec 10 (Decisions) is merged.
 *
 * The Incidents listener `CreateIncidentOnDecisionMade` is registered by string in the
 * service provider and reads attributes via `property_exists`, so this DTO can be
 * substituted in tests without coupling to the future class. When spec 10 lands and
 * the real event is available, this fake should be replaced by the production event class.
 */
class FakeDecisionMadeEvent
{
    public function __construct(
        public string $outcome,
        public int $normalized_event_id,
        public ?int $decision_id = null,
        public ?string $priority_code = null,
        public ?string $incident_type_code = null,
    ) {}
}
