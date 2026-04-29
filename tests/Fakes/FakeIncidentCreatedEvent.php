<?php

namespace Tests\Fakes;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stand-in for `App\Domains\Incidents\Events\IncidentCreated` (spec 11). Dispatched
 * in tests to exercise the string-based listener bound by NotificationsServiceProvider.
 */
class FakeIncidentCreatedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $teamId,
        public readonly int $incidentId,
        public readonly string $incidentType,
        public readonly string $severity = 'normal',
    ) {}
}
