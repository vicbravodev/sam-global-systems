<?php

namespace Tests\Feature\Support;

use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use App\Support\PipelineTrace;
use App\Support\TenantContext;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineTraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Context::flush();
    }

    public function test_begin_starts_a_clean_trace_without_ids_of_the_previous_one(): void
    {
        $first = PipelineTrace::begin(1, 'samsara');
        PipelineTrace::add(['raw_event_id' => 10, 'incident_id' => 20, 'decision_id' => null]);

        $second = PipelineTrace::begin(2);

        $this->assertNotSame($first, $second);
        $this->assertSame([PipelineTrace::TRACE_KEY => $second, PipelineTrace::TEAM_KEY => 2], Context::all());
    }

    public function test_within_runs_in_its_own_trace_and_restores_the_caller(): void
    {
        $operation = PipelineTrace::beginOperation(1);
        Context::add('tenant_id', 1);

        $inner = PipelineTrace::within(null, 1, function (): array {
            PipelineTrace::add(['raw_event_id' => 5]);
            Context::add('tenant_id', 99);

            return Context::all();
        });

        $this->assertNotSame($operation, $inner[PipelineTrace::TRACE_KEY]);
        $this->assertSame($operation, $inner[PipelineTrace::PARENT_KEY]);
        $this->assertSame(5, $inner['raw_event_id']);
        $this->assertSame(
            [PipelineTrace::TRACE_KEY => $operation, PipelineTrace::TEAM_KEY => 1, 'tenant_id' => 1],
            Context::all(),
            'within() no debe dejar rastro de la traza interna en el llamador.',
        );
    }

    public function test_within_the_current_trace_changes_nothing(): void
    {
        $trace = PipelineTrace::begin(1);

        PipelineTrace::within($trace, 1, fn () => PipelineTrace::add(['raw_event_id' => 7]));

        $this->assertSame($trace, PipelineTrace::id());
        $this->assertSame(7, Context::get('raw_event_id'));
    }

    public function test_a_webhook_trace_is_claimed_once_and_only_by_its_own_tenant(): void
    {
        $trace = PipelineTrace::beginEvent(1, 'samsara');

        $this->assertNotSame($trace, PipelineTrace::claimForNewEvent(2), 'Otro tenant nunca reclama la traza.');
        $this->assertSame($trace, PipelineTrace::claimForNewEvent(1));
        $this->assertNotSame($trace, PipelineTrace::claimForNewEvent(1), 'Un segundo evento abre su propia traza.');
    }

    public function test_adopt_prefers_the_persisted_trace_and_never_mixes_tenants(): void
    {
        $current = PipelineTrace::begin(1);
        PipelineTrace::add(['incident_id' => 3]);

        $this->assertSame('01persisted', PipelineTrace::adopt('01persisted', 1, ['raw_event_id' => 4]));
        $this->assertNull(Context::get('incident_id'), 'Los ids de la traza anterior no pasan a la adoptada.');
        $this->assertSame(4, Context::get('raw_event_id'));

        PipelineTrace::begin(1, traceId: $current);
        $this->assertSame($current, PipelineTrace::adopt(null, 1), 'Un evento sin traza sigue en la actual si es del mismo tenant.');

        $this->assertNotSame($current, PipelineTrace::adopt(null, 2), 'Un evento de otro tenant sin traza abre una nueva.');
        $this->assertSame(2, Context::get(PipelineTrace::TEAM_KEY));
    }

    public function test_events_stored_in_a_loop_get_their_own_trace_linked_to_the_operation(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $operation = PipelineTrace::beginOperation($team->id, 'samsara');

        $store = app(StoreRawEvent::class);
        $first = $store->execute(['id' => 'a'], EventSourceType::PollingFeed->value, $team->id, null, 'a');
        $second = $store->execute(['id' => 'b'], EventSourceType::PollingFeed->value, $team->id, null, 'b');

        $this->assertNotNull($first->trace_id);
        $this->assertNotSame($first->trace_id, $second->trace_id);
        $this->assertNotSame($operation, $first->trace_id);
        $this->assertSame($operation, PipelineTrace::id(), 'El bucle sigue en la traza de la operación.');
        $this->assertNull(Context::get('raw_event_id'));
    }

    public function test_a_webhook_trace_is_persisted_on_the_raw_event_it_brought(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $trace = PipelineTrace::beginEvent($team->id, 'samsara');

        $rawEvent = app(StoreRawEvent::class)->execute(['eventId' => 'x'], EventSourceType::Webhook->value, $team->id, null, 'x');

        $this->assertSame($trace, $rawEvent->trace_id);
        $this->assertSame($rawEvent->id, Context::get('raw_event_id'));
    }

    public function test_a_platform_loop_gives_each_tenant_its_own_trace_linked_to_the_sweep(): void
    {
        Queue::fake();
        [$teamA, $teamB] = Team::factory()->count(2)->create()->all();
        $seen = [];
        Event::listen(RawEventReceived::class, function (RawEventReceived $event) use (&$seen): void {
            $seen[$event->rawEvent->team_id] = Context::only([PipelineTrace::TRACE_KEY, PipelineTrace::TEAM_KEY, PipelineTrace::PARENT_KEY]);
        });

        $sweep = PipelineTrace::begin(null);
        $store = app(StoreRawEvent::class);
        $a = TenantContext::for($teamA, fn () => $store->execute(['id' => 'a'], EventSourceType::InternalMonitor->value, $teamA->id, null, 'a'));
        $b = TenantContext::for($teamB, fn () => $store->execute(['id' => 'b'], EventSourceType::InternalMonitor->value, $teamB->id, null, 'b'));

        $this->assertNotSame($a->trace_id, $b->trace_id);
        $this->assertSame([PipelineTrace::TRACE_KEY => $a->trace_id, PipelineTrace::TEAM_KEY => $teamA->id, PipelineTrace::PARENT_KEY => $sweep], $seen[$teamA->id]);
        $this->assertSame([PipelineTrace::TRACE_KEY => $b->trace_id, PipelineTrace::TEAM_KEY => $teamB->id, PipelineTrace::PARENT_KEY => $sweep], $seen[$teamB->id]);
        $this->assertSame([PipelineTrace::TRACE_KEY => $sweep], Context::all());
    }

    public function test_a_trace_is_never_parent_of_another_tenants_trace(): void
    {
        PipelineTrace::begin(1);

        PipelineTrace::beginOperation(2);
        $this->assertNull(Context::get(PipelineTrace::PARENT_KEY));

        $parentOfTeamTwo = PipelineTrace::id();
        PipelineTrace::within(null, 1, fn () => $this->assertNull(Context::get(PipelineTrace::PARENT_KEY)));
        PipelineTrace::within(null, 2, fn () => $this->assertSame($parentOfTeamTwo, Context::get(PipelineTrace::PARENT_KEY)));
    }

    public function test_a_job_arriving_with_another_tenants_trace_opens_its_own(): void
    {
        $notification = Notification::factory()->create(['status' => NotificationStatus::Sent]);
        $foreign = PipelineTrace::begin(Team::factory()->create()->id);
        PipelineTrace::add(['incident_id' => 99]);

        app()->call([new SendNotificationJob($notification->id), 'handle']);

        $this->assertNotSame($foreign, PipelineTrace::id());
        $this->assertSame($notification->team_id, Context::get(PipelineTrace::TEAM_KEY));
        $this->assertSame($notification->id, Context::get('notification_id'));
        $this->assertNull(Context::get('incident_id'));
        $this->assertNull(Context::get(PipelineTrace::PARENT_KEY));
    }

    public function test_a_job_dispatched_without_trace_opens_one_in_its_tenant(): void
    {
        $team = Team::factory()->create();
        $seen = null;

        Event::listen(JobProcessed::class, function () use (&$seen): void {
            $seen = Context::only([PipelineTrace::TRACE_KEY, PipelineTrace::TEAM_KEY]);
        });

        TenantContext::for($team, function (): void {
            dispatch(function (): void {});
        });

        $this->assertNotNull($seen[PipelineTrace::TRACE_KEY] ?? null);
        $this->assertSame($team->id, $seen[PipelineTrace::TEAM_KEY]);
    }

    public function test_every_scheduled_task_starts_its_own_platform_trace(): void
    {
        PipelineTrace::begin(1);
        PipelineTrace::add(['raw_event_id' => 5]);
        $previous = PipelineTrace::id();

        event(new ScheduledTaskStarting(new CallbackEvent(app(CacheEventMutex::class), fn () => null)));

        $this->assertNotSame($previous, PipelineTrace::id());
        $this->assertSame([PipelineTrace::TRACE_KEY => PipelineTrace::id()], Context::all());
    }

    public function test_the_json_channel_writes_the_trace_on_every_line_but_not_hidden_context(): void
    {
        $path = storage_path('framework/testing/pipeline-trace-'.bin2hex(random_bytes(4)).'.json');
        config(['logging.channels.json.path' => $path, 'logging.channels.json.driver' => 'single']);

        $trace = PipelineTrace::beginEvent(42, 'samsara');
        Context::addHidden('secret', 'no-debe-salir');
        Log::channel('json')->info('evento recibido', ['raw_event_id' => 1]);

        $line = json_decode(trim(File::get($path)), true);
        File::delete($path);

        $this->assertSame('evento recibido', $line['message']);
        $this->assertSame($trace, $line['extra']['trace_id']);
        $this->assertSame(42, $line['extra']['team_id']);
        $this->assertSame('samsara', $line['extra']['provider']);
        $this->assertStringNotContainsString('no-debe-salir', (string) json_encode($line));
        $this->assertArrayNotHasKey('pipeline_trace_claimable', $line['extra']);
    }
}
