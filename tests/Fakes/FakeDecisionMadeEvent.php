<?php

namespace Tests\Fakes;

/**
 * Stand-in for `App\Domains\Decisions\Events\DecisionMade` used by cross-domain
 * listener tests in spec-11 (Incidents) and spec-12 (Automation). Both sets of
 * listeners read public properties via reflection / property_exists, so this fake
 * exposes the union of all properties either listener consumes.
 */
class FakeDecisionMadeEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly ?int $teamId = null,
        public readonly ?int $decisionId = null,
        public readonly array $payload = [],
        public readonly ?string $outcome = null,
        public readonly ?int $normalized_event_id = null,
        public readonly ?int $decision_id = null,
        public readonly ?string $priority_code = null,
        public readonly ?string $incident_type_code = null,
    ) {}
}
