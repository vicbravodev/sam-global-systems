<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Jobs\EnrichContextJob;
use App\Domains\Context\Listeners\EnrichContextOnEventNormalized;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EnrichContextJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_listener_dispatches_enrich_context_job_on_event_normalized(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        (new EnrichContextOnEventNormalized)->handle(new EventNormalized($event));

        Bus::assertDispatched(EnrichContextJob::class, fn (EnrichContextJob $job) => $job->normalizedEventId === $event->id);
    }

    public function test_job_handle_builds_snapshot_for_existing_event(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        (new EnrichContextJob($event->id))->handle(app(BuildEventContext::class));

        $this->assertDatabaseHas('event_context_snapshots', ['normalized_event_id' => $event->id]);
    }

    public function test_job_handle_no_ops_when_event_missing(): void
    {
        (new EnrichContextJob(99999))->handle(app(BuildEventContext::class));

        $this->assertSame(0, EventContextSnapshot::withoutGlobalScopes()->count());
    }

    public function test_job_unique_id_is_normalized_event_id(): void
    {
        $job = new EnrichContextJob(42);

        $this->assertSame('42', $job->uniqueId());
    }

    public function test_failed_method_marks_event_as_failed(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        (new EnrichContextJob($event->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(NormalizedEventStatus::Failed, $event->fresh()->status);
    }

    public function test_failed_method_handles_missing_event(): void
    {
        (new EnrichContextJob(99999))->failed(new \RuntimeException('boom'));

        $this->assertTrue(true);
    }
}
