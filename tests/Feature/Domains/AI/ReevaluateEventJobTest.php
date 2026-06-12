<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Coalescing guard for re-evaluation bursts: deferred media lands clip by
 * clip and each assessment requests its own re-evaluation, so the job must
 * dedupe per (event, trigger) while a previous request is still queued —
 * without ever swallowing the evidence that arrives after a run started.
 */
class ReevaluateEventJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ReevaluateEventJob::class]);
    }

    public function test_media_arrived_dispatches_are_unique_per_event_while_queued(): void
    {
        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value, 1, 'clip 1');
        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value, 2, 'clip 2');
        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value, 3, 'clip 3');

        Bus::assertDispatchedTimes(ReevaluateEventJob::class, 1);
    }

    public function test_different_events_or_triggers_do_not_share_the_lock(): void
    {
        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value);
        ReevaluateEventJob::dispatch(202, ReevaluationTrigger::MediaArrived->value);
        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::ManualReviewRequested->value);

        Bus::assertDispatchedTimes(ReevaluateEventJob::class, 3);
    }

    public function test_lock_releases_when_processing_starts_so_late_footage_reopens_the_pipeline(): void
    {
        $job = new ReevaluateEventJob(101, ReevaluationTrigger::MediaArrived->value);

        // The framework releases the unique lock as soon as the worker picks
        // the job up — that contract is what guarantees media assessed while
        // a re-evaluation is running still ends up reflected in a decision.
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);

        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value);
        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value);

        Bus::assertDispatchedTimes(ReevaluateEventJob::class, 1);

        // Simulate the worker starting the queued job (lock released).
        (new UniqueLock(Cache::store()))->release($job);

        ReevaluateEventJob::dispatch(101, ReevaluationTrigger::MediaArrived->value);

        Bus::assertDispatchedTimes(ReevaluateEventJob::class, 2);
    }
}
