<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FetchDeferredEventMediaJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_handle_marks_pending_request_as_sent(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        app(BuildEventContext::class)->execute($event);

        $request = EventMediaRequest::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'request_type' => MediaRequestType::FetchVideoClip,
            'status' => MediaRequestStatus::Pending,
        ]);

        (new FetchDeferredEventMediaJob($request->id))->handle(app(RefreshContextMediaSnapshot::class));

        $this->assertSame(MediaRequestStatus::Sent, $request->fresh()->status);
    }

    public function test_handle_no_ops_when_request_already_completed(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = EventMediaRequest::factory()->completed()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        (new FetchDeferredEventMediaJob($request->id))->handle(app(RefreshContextMediaSnapshot::class));

        $this->assertSame(MediaRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_handle_no_ops_when_request_missing(): void
    {
        (new FetchDeferredEventMediaJob(99999))->handle(app(RefreshContextMediaSnapshot::class));

        $this->assertTrue(true);
    }

    public function test_failed_marks_request_failed_and_dispatches_event(): void
    {
        Event::fake([EventMediaFailed::class]);

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = EventMediaRequest::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'status' => MediaRequestStatus::Sent,
        ]);

        (new FetchDeferredEventMediaJob($request->id))->failed(new \RuntimeException('provider down'));

        $request = $request->fresh();
        $this->assertSame(MediaRequestStatus::Failed, $request->status);
        $this->assertNotNull($request->completed_at);

        Event::assertDispatched(
            EventMediaFailed::class,
            fn (EventMediaFailed $e) => $e->request->id === $request->id && str_contains($e->reason, 'provider down'),
        );
    }
}
