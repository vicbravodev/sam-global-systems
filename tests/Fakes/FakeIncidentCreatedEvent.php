<?php

namespace Tests\Fakes;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stand-in for `App\Domains\Incidents\Events\IncidentCreated` used by cross-domain
 * listener tests in spec-12 (Automation) and spec-13 (Notifications) without coupling
 * to the production event class.
 */
class FakeIncidentCreatedEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $teamId,
        public readonly int $incidentId,
        public readonly string $incidentType = 'unknown',
        public readonly string $severity = 'normal',
        public readonly array $payload = [],
    ) {}
}
