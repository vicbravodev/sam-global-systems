<?php

namespace Tests\Fakes;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stand-in for `App\Domains\Automation\Events\ActionExecutionCompleted` (spec 12).
 */
class FakeActionExecutionCompletedEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $teamId,
        public readonly int $executionId,
        public readonly string $actionType,
        public readonly array $payload = [],
    ) {}
}
