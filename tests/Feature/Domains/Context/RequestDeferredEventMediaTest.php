<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\RequestDeferredEventMedia;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\ContextMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RequestDeferredEventMediaTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_creates_pending_request_and_dispatches_fetch_job(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchVideoClip);

        $this->assertSame(MediaRequestStatus::Pending, $request->status);
        $this->assertSame(MediaRequestType::FetchVideoClip, $request->request_type);
        $this->assertSame($this->teamId, $request->team_id);
        $this->assertSame($event->id, $request->normalized_event_id);

        Bus::assertDispatched(
            FetchDeferredEventMediaJob::class,
            fn (FetchDeferredEventMediaJob $job) => $job->eventMediaRequestId === $request->id,
        );
    }

    public function test_reuses_in_flight_request_for_same_event_and_type(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $first = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchSnapshot);
        $second = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchSnapshot);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            EventMediaRequest::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->where('request_type', MediaRequestType::FetchSnapshot->value)
                ->count(),
        );

        Bus::assertDispatchedTimes(FetchDeferredEventMediaJob::class, 1);
    }

    public function test_persists_sweep_only_flag_when_requested(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchVideoClip, sweepOnly: true);

        $this->assertTrue($request->sweep_only);
    }

    public function test_sweep_only_request_does_not_block_an_on_demand_retrieval(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        // Auto panic sweep is in flight; the operator then clicks "request media"
        // which must still place a real (non-sweep) on-demand retrieval.
        $sweep = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchVideoClip, sweepOnly: true);
        $retrieval = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchVideoClip);

        $this->assertNotSame($sweep->id, $retrieval->id);
        $this->assertTrue($sweep->sweep_only);
        $this->assertFalse($retrieval->sweep_only);
        $this->assertSame(
            2,
            EventMediaRequest::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->where('request_type', MediaRequestType::FetchVideoClip->value)
                ->count(),
        );
    }

    public function test_creates_new_request_after_previous_was_completed(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        EventMediaRequest::factory()->completed()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'request_type' => MediaRequestType::FetchVideoClip,
        ]);

        $request = app(RequestDeferredEventMedia::class)
            ->execute($event, MediaRequestType::FetchVideoClip);

        $this->assertSame(MediaRequestStatus::Pending, $request->status);
        $this->assertSame(
            2,
            EventMediaRequest::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->count(),
        );
    }

    public function test_logs_reuse_of_in_flight_request(): void
    {
        Bus::fake();
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $first = app(RequestDeferredEventMedia::class)->execute($event, MediaRequestType::FetchSnapshot);
        app(RequestDeferredEventMedia::class)->execute($event, MediaRequestType::FetchSnapshot);

        $c = $this->assertSystemLogged('context.media.request_reused', fn (array $c) => $c['reason'] === 'request_in_flight');
        $this->assertSame($event->id, $c['input']['normalized_event_id']);
        $this->assertSame('fetch_snapshot', $c['input']['request_type']);
        $this->assertFalse($c['input']['sweep_only']);
        $this->assertSame($first->id, $c['result']['event_media_request_id']);
        $this->assertSame('pending', $c['result']['status']);
        $this->assertCount(1, $this->systemLogEntries('context.media.requested'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_logs_usage_not_metered_when_meter_missing(): void
    {
        Bus::fake();
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = app(RequestDeferredEventMedia::class)->execute($event, MediaRequestType::FetchVideoClip);

        $c = $this->assertSystemLogged('context.usage.not_metered', fn (array $c) => $c['reason'] === 'meter_missing');
        $this->assertSame('media_requests', $c['input']['meter_code']);
        $this->assertSame($request->id, $c['input']['event_media_request_id']);
        $this->assertSystemLogged('context.media.requested', fn (array $c) => $c['result']['event_media_request_id'] === $request->id);
        $this->assertSystemNotLogged('context.usage.recorded');
    }

    public function test_logs_usage_recorded_when_meter_exists(): void
    {
        Bus::fake();
        $this->seed(ContextMeterSeeder::class);
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = app(RequestDeferredEventMedia::class)->execute($event, MediaRequestType::FetchVideoClip);

        $c = $this->assertSystemLogged('context.usage.recorded');
        $this->assertSame($request->id, $c['input']['event_media_request_id']);
        $this->assertSystemNotLogged('context.usage.not_metered');
        $this->assertNoSensitiveDataLogged();
    }
}
