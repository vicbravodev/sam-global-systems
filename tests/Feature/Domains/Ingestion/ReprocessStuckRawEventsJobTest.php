<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Jobs\ReprocessStuckRawEventsJob;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Notifications\PipelineFailureNotification;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class ReprocessStuckRawEventsJobTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    private User $ownerA;

    private User $ownerB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pipeline.reprocess.stuck_after_minutes' => 15,
            'pipeline.reprocess.max_attempts' => 3,
            'pipeline.reprocess.max_age_hours' => 24,
            'pipeline.reprocess.batch_size' => 100,
        ]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['global_role' => 'super_admin'])->save();
        $this->ownerA = User::factory()->create();
        $this->teamA = $this->ownerA->currentTeam;
        $this->ownerB = User::factory()->create();
        $this->teamB = $this->ownerB->currentTeam;
    }

    private function sweep(): void
    {
        app()->call([new ReprocessStuckRawEventsJob, 'handle']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stuck(Team $team, RawEventStatus $status = RawEventStatus::Failed, int $minutesAgo = 30, array $attributes = []): RawEvent
    {
        $raw = RawEvent::factory()->create([
            'team_id' => $team->id,
            'event_source_id' => EventSource::factory()->create(['team_id' => $team->id])->id,
            'status' => $status,
            'received_at' => now()->subMinutes($minutesAgo),
            ...$attributes,
        ]);

        RawEvent::withoutGlobalScopes()->whereKey($raw->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $raw->fresh();
    }

    private function panicType(): EventType
    {
        $category = EventCategory::query()->where('code', 'emergency')->first()
            ?? EventCategory::factory()->emergency()->create(['code' => 'emergency']);

        return EventType::query()->where('code', 'panic_button')->first()
            ?? EventType::factory()->create(['code' => 'panic_button', 'category_id' => $category->id]);
    }

    public function test_stuck_events_of_every_tenant_are_redispatched_and_counted(): void
    {
        Queue::fake();
        $failedA = $this->stuck($this->teamA);
        $pendingB = $this->stuck($this->teamB, RawEventStatus::PendingProcessing);
        $processingA = $this->stuck($this->teamA, RawEventStatus::Processing);
        $orphanProcessedB = $this->stuck($this->teamB, RawEventStatus::Processed);

        $this->sweep();

        foreach ([$failedA, $pendingB, $processingA, $orphanProcessedB] as $raw) {
            Queue::assertPushed(ProcessRawEventJob::class, fn (ProcessRawEventJob $job) => $job->rawEventId === $raw->id);
            $fresh = RawEvent::withoutGlobalScopes()->find($raw->id);
            $this->assertSame(1, $fresh->reprocess_attempts);
            $this->assertNotNull($fresh->last_reprocessed_at);
            $this->assertSame(RawEventStatus::PendingProcessing, $fresh->status);
        }

        $c = $this->assertSystemLogged('ingestion.reprocess_sweep.completed');
        $this->assertSame(2, $c['result']['teams_count']);
        $this->assertSame(4, $c['result']['dispatched_count']);
        $this->assertSystemLogged('ingestion.reprocess.dispatched', fn (array $c) => $c['input']['raw_event_id'] === $failedA->id
            && $c['input']['previous_status'] === 'failed'
            && $c['calc']['reprocess_attempt'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_healthy_recent_old_and_finished_events_are_left_alone(): void
    {
        Queue::fake();
        $recent = $this->stuck($this->teamA, RawEventStatus::Failed, minutesAgo: 5);
        $tooOld = $this->stuck($this->teamA, RawEventStatus::Failed, minutesAgo: 60 * 30);
        $duplicate = $this->stuck($this->teamA, RawEventStatus::DuplicateDetected);
        $discarded = $this->stuck($this->teamA, RawEventStatus::Discarded);
        $done = $this->stuck($this->teamA, RawEventStatus::Processed);
        NormalizedEvent::factory()->create(['team_id' => $this->teamA->id, 'raw_event_id' => $done->id]);

        $this->sweep();

        Queue::assertNotPushed(ProcessRawEventJob::class);
        foreach ([$recent, $tooOld, $duplicate, $discarded, $done] as $raw) {
            $this->assertSame(0, RawEvent::withoutGlobalScopes()->find($raw->id)->reprocess_attempts);
        }
    }

    public function test_emergencies_go_first_when_the_batch_is_full(): void
    {
        Queue::fake();
        config(['pipeline.reprocess.batch_size' => 1]);
        $this->panicType();

        $olderRoutine = $this->stuck($this->teamA, minutesAgo: 120, attributes: ['provider_id' => null, 'event_type_raw' => 'harsh_brake']);
        $panic = $this->stuck($this->teamB, minutesAgo: 20, attributes: ['provider_id' => null, 'event_type_raw' => 'panic_button']);

        $this->sweep();

        Queue::assertPushed(ProcessRawEventJob::class, 1);
        Queue::assertPushed(ProcessRawEventJob::class, fn (ProcessRawEventJob $job) => $job->rawEventId === $panic->id);
        $this->assertSame(0, RawEvent::withoutGlobalScopes()->find($olderRoutine->id)->reprocess_attempts);

        $c = $this->assertSystemLogged('ingestion.reprocess_sweep.completed');
        $this->assertSame(1, $c['result']['emergency_dispatched_count']);
        $this->assertSame(1, $c['result']['deferred_count']);
    }

    public function test_an_event_that_exhausts_its_rescues_stops_and_alerts_once(): void
    {
        Queue::fake();
        Notification::fake();
        $this->panicType();
        $raw = $this->stuck($this->teamA, attributes: ['reprocess_attempts' => 3, 'provider_id' => null, 'event_type_raw' => 'panic_button']);

        $this->sweep();
        $this->travel(10)->minutes();
        $this->sweep();

        Queue::assertNotPushed(ProcessRawEventJob::class);
        Notification::assertSentToTimes($this->superAdmin, PipelineFailureNotification::class, 1);
        Notification::assertSentToTimes($this->ownerA, PipelineFailureNotification::class, 1);
        Notification::assertNotSentTo($this->ownerB, PipelineFailureNotification::class);

        $alert = PipelineFailureAlert::withoutGlobalScopes()->sole();
        $this->assertSame(PipelineFailureAlert::KIND_REPROCESS_EXHAUSTED, $alert->kind);
        $this->assertSame($raw->id, (int) $alert->raw_event_id);
        $this->assertSame($this->teamA->id, (int) $alert->team_id);

        $c = $this->assertSystemLogged('ingestion.reprocess.exhausted');
        $this->assertSame('max_reprocess_attempts', $c['reason']);
        $this->assertSame(3, $c['calc']['reprocess_attempts']);
    }

    public function test_the_attempt_counter_caps_the_rescues(): void
    {
        Queue::fake();
        Notification::fake();
        $raw = $this->stuck($this->teamA);

        for ($i = 0; $i < 5; $i++) {
            $this->sweep();
            // Simula que el rescate volvió a fallar y pasó el umbral.
            RawEvent::withoutGlobalScopes()->whereKey($raw->id)->update(['status' => RawEventStatus::Failed]);
            $this->travel(20)->minutes();
        }

        Queue::assertPushed(ProcessRawEventJob::class, 3);
        $this->assertSame(3, RawEvent::withoutGlobalScopes()->find($raw->id)->reprocess_attempts);
        Notification::assertSentToTimes($this->superAdmin, PipelineFailureNotification::class, 1);
    }

    public function test_the_sweep_never_touches_another_tenants_rows(): void
    {
        Queue::fake();
        $stuckB = $this->stuck($this->teamB);
        // El tenant A tiene eventos sanos, recientes y uno terminado.
        $this->stuck($this->teamA, RawEventStatus::Failed, minutesAgo: 2);
        $doneA = $this->stuck($this->teamA, RawEventStatus::Processed);
        NormalizedEvent::factory()->create(['team_id' => $this->teamA->id, 'raw_event_id' => $doneA->id]);

        $this->assertNoTenantLeak($this->teamB, fn () => $this->sweep());

        Queue::assertPushed(ProcessRawEventJob::class, 1);
        Queue::assertPushed(ProcessRawEventJob::class, fn (ProcessRawEventJob $job) => $job->rawEventId === $stuckB->id);
    }

    public function test_reprocessing_an_emergency_that_already_opened_its_incident_does_not_open_another(): void
    {
        $this->seed(IncidentsSeeder::class);
        Notification::fake();
        Queue::fake()->except([ProcessRawEventJob::class, NormalizeEventJob::class, OpenEmergencyIncidentJob::class]);
        $this->panicType();
        $asset = Asset::factory()->create(['team_id' => $this->teamA->id]);

        $raw = RawEvent::factory()->create([
            'team_id' => $this->teamA->id,
            'event_source_id' => EventSource::factory()->create(['team_id' => $this->teamA->id])->id,
            'provider_id' => null,
            'event_type_raw' => 'panic_button',
            'deduplication_key' => 'panic-1',
            'payload_json' => ['internal' => ['asset_id' => $asset->id]],
        ]);

        app(QueueRawEventForProcessing::class)->execute($raw);
        $this->assertSame(1, Incident::withoutGlobalScopes()->count());

        // Algo posterior dejó el raw event en `failed` y el barrido lo rescata.
        RawEvent::withoutGlobalScopes()->whereKey($raw->id)->update(['status' => RawEventStatus::Failed]);
        $this->travel(20)->minutes();

        $this->sweep();

        $fresh = RawEvent::withoutGlobalScopes()->find($raw->id);
        $this->assertSame(RawEventStatus::Processed, $fresh->status, 'El dedup reconoce su propia clave: no es un duplicado.');
        $this->assertSame(1, $fresh->reprocess_attempts);
        $this->assertSame(1, NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $raw->id)->count());
        $this->assertSame(1, Incident::withoutGlobalScopes()->count(), 'El rescate no abre un segundo incidente.');
        $this->assertSystemLogged('ingestion.dedup.own_key', fn (array $c) => $c['input']['raw_event_id'] === $raw->id);
        $this->assertSystemLogged('incidents.emergency.job_skipped', fn (array $c) => ($c['reason'] ?? null) === 'incident_exists');
    }
}
