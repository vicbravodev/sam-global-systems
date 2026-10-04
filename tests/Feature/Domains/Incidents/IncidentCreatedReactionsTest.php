<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Listeners\FreezeLocationTrailOnIncidentCreated;
use App\Domains\Assets\Models\Asset;
use App\Domains\Automation\Listeners\TriggerAutomationOnIncidentCreated;
use App\Domains\Context\Listeners\RequestMediaOnIncidentCreated;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Actions\CreateManualIncident;
use App\Domains\Incidents\Actions\RecordIncidentWorkflowUsage;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\RetryIncidentCreatedReactionJob;
use App\Domains\Incidents\Jobs\RetryIncidentWorkflowUsageJob;
use App\Domains\Incidents\Listeners\AssignOnCallOnIncidentCreated;
use App\Domains\Incidents\Listeners\StartCallVerificationOnIncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Incidents\Support\IncidentCreatedBroadcast;
use App\Domains\Incidents\Support\IncidentCreatedReaction;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Listeners\NotifyOnIncidentCreated;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\IncidentMeterSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Los efectos de IncidentCreated (aviso, asignación on-call, llamada de
 * verificación, automatizaciones, congelado del recorrido GPS) corren tras el
 * commit del incidente, cada uno en su propia transacción: el fallo de uno no
 * revierte el incidente ni impide a los demás, queda en el log y se reintenta
 * en cola.
 */
class IncidentCreatedReactionsTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    /** @var array<class-string<IncidentCreatedReaction>, string> */
    private const array REACTIONS = [
        NotifyOnIncidentCreated::class => 'notifications',
        AssignOnCallOnIncidentCreated::class => 'incidents',
        StartCallVerificationOnIncidentCreated::class => 'incidents',
        TriggerAutomationOnIncidentCreated::class => 'automation',
        FreezeLocationTrailOnIncidentCreated::class => 'incidents',
        RequestMediaOnIncidentCreated::class => 'context',
    ];

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
        $this->team = User::factory()->create()->currentTeam;
    }

    /**
     * @return array<string, array{class-string<IncidentCreatedReaction>, string}>
     */
    public static function reactions(): array
    {
        $cases = [];

        foreach (self::REACTIONS as $class => $queue) {
            $cases[class_basename($class)] = [$class, $queue];
        }

        return $cases;
    }

    private function panicEvent(?Team $team = null): NormalizedEvent
    {
        $team ??= $this->team;

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => Asset::factory()->create(['team_id' => $team->id])->id,
        ]);
    }

    private function openPanic(NormalizedEvent $event): Incident
    {
        return app(CreateIncidentFromEvent::class)->execute($event, [
            'incident_type_code' => 'panic_emergency',
            'priority_code' => 'critical',
        ]);
    }

    /**
     * Sustituye cada reacción por un parcial cuyo `react()` registra que corrió
     * (o lanza, si es la que debe fallar); `handle()` y `retryQueue()` son los
     * reales.
     *
     * @param  list<class-string>  $ran
     */
    private function spyReactions(?string $failing, array &$ran): void
    {
        foreach (array_keys(self::REACTIONS) as $class) {
            $this->partialMock($class, function (MockInterface $mock) use ($class, $failing, &$ran) {
                $expectation = $mock->shouldReceive('react')->once();

                if ($class === $failing) {
                    $expectation->andThrow(new RuntimeException('boom'));

                    return;
                }

                $expectation->andReturnUsing(function () use ($class, &$ran) {
                    $ran[] = $class;
                });
            });
        }
    }

    #[DataProvider('reactions')]
    public function test_a_failing_reaction_keeps_the_incident_and_the_others_still_run(string $failing, string $queue): void
    {
        Queue::fake([RetryIncidentCreatedReactionJob::class]);
        $ran = [];
        $this->spyReactions($failing, $ran);

        $incident = $this->openPanic($this->panicEvent());

        $this->assertTrue(Incident::withoutGlobalScopes()->whereKey($incident->id)->exists(), 'El incidente sobrevive al fallo de un efecto.');
        $this->assertTrue(IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::Created)
            ->exists());
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('event_key', 'incident_workflows:'.$incident->id)->count());

        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_keys(self::REACTIONS), [$failing])),
            $ran,
            'Los demás efectos corren aunque uno falle.',
        );

        $c = $this->assertSystemLogged('incidents.created_reaction.failed');
        $this->assertSame('failed', $c['outcome']);
        $this->assertSame('exception', $c['reason']);
        $this->assertSame(class_basename($failing), $c['input']['reaction']);
        $this->assertSame($incident->id, $c['input']['incident_id']);
        $this->assertSame('inline', $c['input']['stage']);
        $this->assertTrue($c['result']['retry_requested']);
        $this->assertSame($queue, $c['result']['retry_queue']);
        $this->assertSame(RuntimeException::class, $c['error']['class']);
        $this->assertCount(1, $this->systemLogEntries('incidents.created_reaction.failed'));
        $this->assertSystemLogged('incidents.incident.created');
        $this->assertNoSensitiveDataLogged();

        Queue::assertPushedOn($queue, RetryIncidentCreatedReactionJob::class, fn (RetryIncidentCreatedReactionJob $job) => $job->reaction === $failing
            && $job->incidentId === $incident->id
            && $job->teamId === $this->team->id);
        Queue::assertPushed(RetryIncidentCreatedReactionJob::class, 1);
    }

    public function test_a_failing_reaction_rolls_back_only_its_own_writes(): void
    {
        Queue::fake([RetryIncidentCreatedReactionJob::class]);

        $this->partialMock(NotifyOnIncidentCreated::class, function (MockInterface $mock) {
            $mock->shouldReceive('react')->once()->andReturnUsing(function (IncidentCreated $event) {
                IncidentTimeline::factory()->create([
                    'team_id' => $event->incident->team_id,
                    'incident_id' => $event->incident->id,
                    'entry_type' => TimelineEntryType::CommentAdded,
                    'actor_type' => TimelineActorType::System,
                ]);

                throw new RuntimeException('boom');
            });
        });

        $incident = $this->openPanic($this->panicEvent());

        $this->assertTrue(Incident::withoutGlobalScopes()->whereKey($incident->id)->exists());
        $this->assertFalse(IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::CommentAdded)
            ->exists(), 'La escritura a medias del efecto fallido revierte con él.');
    }

    public function test_the_reactions_run_only_after_the_incident_commits(): void
    {
        Event::fake([IncidentCreated::class, IncidentCreatedBroadcast::class]);

        DB::transaction(function () {
            $this->openPanic($this->panicEvent());

            Event::assertNotDispatched(IncidentCreated::class);
            Event::assertNotDispatched(IncidentCreatedBroadcast::class);
        });

        Event::assertDispatched(IncidentCreated::class, 1);
        Event::assertDispatched(IncidentCreatedBroadcast::class, 1);
    }

    public function test_a_rolled_back_creation_never_runs_a_reaction_nor_broadcasts(): void
    {
        Event::fake([IncidentCreated::class, IncidentCreatedBroadcast::class]);

        try {
            DB::transaction(function () {
                $this->openPanic($this->panicEvent());

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
        Event::assertNotDispatched(IncidentCreated::class);
        Event::assertNotDispatched(IncidentCreatedBroadcast::class);
    }

    public function test_a_manual_incident_also_reacts_after_commit_and_survives_a_failing_reaction(): void
    {
        Queue::fake([RetryIncidentCreatedReactionJob::class]);
        $ran = [];
        $this->spyReactions(NotifyOnIncidentCreated::class, $ran);
        $creator = User::factory()->create();

        $incident = app(CreateManualIncident::class)->execute($this->team->id, $creator, [
            'incident_type_id' => IncidentType::query()->where('code', 'panic_emergency')->value('id'),
            'title' => 'Manual',
            'summary' => 'Manual',
        ]);

        $this->assertTrue(Incident::withoutGlobalScopes()->whereKey($incident->id)->exists());
        $this->assertCount(count(self::REACTIONS) - 1, $ran);
        $this->assertSystemLogged('incidents.created_reaction.failed', fn (array $c) => $c['input']['reaction'] === 'NotifyOnIncidentCreated');
    }

    public function test_a_failing_usage_record_never_loses_the_incident(): void
    {
        Bus::fake();
        UsageMeter::query()->where('code', 'incident_workflows')->delete();
        Cache::forget('usage_meter:incident_workflows');

        $incident = $this->openPanic($this->panicEvent());

        $this->assertTrue(Incident::withoutGlobalScopes()->whereKey($incident->id)->exists());
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $this->assertTrue(Notification::withoutGlobalScopes()->where('event_key', 'incident_created:'.$incident->id)->exists(), 'Los efectos corren aunque el cobro falle.');

        $c = $this->assertSystemLogged('incidents.usage.record_failed');
        $this->assertSame('failed', $c['outcome']);
        $this->assertSame('exception', $c['reason']);
        $this->assertSame($incident->id, $c['input']['incident_id']);
        $this->assertSame('incident_workflows', $c['input']['meter_code']);
        $this->assertSame('incident_workflows:'.$incident->id, $c['input']['event_key']);
        $this->assertTrue($c['result']['retry_requested']);
        $this->assertSame('billing', $c['result']['retry_queue']);
        $this->assertSystemLogged('incidents.incident.created', fn (array $c) => $c['result']['usage_recorded'] === false);
        $this->assertNoSensitiveDataLogged();

        Bus::assertDispatched(RetryIncidentWorkflowUsageJob::class, fn (RetryIncidentWorkflowUsageJob $job) => $job->incidentId === $incident->id
            && $job->teamId === $this->team->id
            && $job->queue === 'billing');
    }

    public function test_a_failing_usage_record_whose_retry_cannot_be_queued_is_logged_as_unavailable(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);
        UsageMeter::query()->where('code', 'incident_workflows')->delete();
        Cache::forget('usage_meter:incident_workflows');
        // Ninguna conexión de cola resoluble: el reintento no se puede encolar.
        config(['queue.default' => 'cola-inexistente']);

        $recorded = app(RecordIncidentWorkflowUsage::class)->execute($incident, ['source' => 'test']);

        $this->assertFalse($recorded);
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('incidents.usage.record_failed', fn (array $c) => $c['result']['retry_requested'] === false);
        $c = $this->assertSystemLogged('incidents.usage.retry_unavailable');
        $this->assertSame('failed', $c['outcome']);
        $this->assertSame('dispatch_failed', $c['reason']);
        $this->assertSame($incident->id, $c['input']['incident_id']);
        $this->assertSame($this->team->id, $c['input']['team_id']);
        $this->assertSame('incident_workflows:'.$incident->id, $c['input']['event_key']);
        $this->assertArrayHasKey('error', $c);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_failing_reaction_whose_retry_cannot_be_queued_is_logged_as_unavailable(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);
        $listener = $this->partialMock(NotifyOnIncidentCreated::class, function (MockInterface $mock) {
            $mock->shouldReceive('react')->once()->andThrow(new RuntimeException('boom'));
        });
        config(['queue.default' => 'cola-inexistente']);

        $listener->handle(new IncidentCreated($incident));

        $this->assertSystemLogged('incidents.created_reaction.failed', fn (array $c) => $c['result']['retry_requested'] === false);
        $c = $this->assertSystemLogged('incidents.created_reaction.retry_unavailable');
        $this->assertSame('failed', $c['outcome']);
        $this->assertSame('dispatch_failed', $c['reason']);
        $this->assertSame('NotifyOnIncidentCreated', $c['input']['reaction']);
        $this->assertSame($incident->id, $c['input']['incident_id']);
        $this->assertSame('notifications', $c['result']['retry_queue']);
        $this->assertArrayHasKey('error', $c);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_usage_retry_records_the_charge_exactly_once(): void
    {
        Bus::fake();
        UsageMeter::query()->where('code', 'incident_workflows')->delete();
        Cache::forget('usage_meter:incident_workflows');

        $incident = $this->openPanic($this->panicEvent());

        $retry = null;
        Bus::assertDispatched(RetryIncidentWorkflowUsageJob::class, function (RetryIncidentWorkflowUsageJob $job) use (&$retry) {
            $retry = $job;

            return true;
        });

        // El meter vuelve (p. ej. se corrió el seeder que faltaba).
        $this->seed(IncidentMeterSeeder::class);
        Cache::forget('usage_meter:incident_workflows');
        Context::flush();

        app()->call([$retry, 'handle']);
        app()->call([$retry, 'handle']);

        $usage = UsageEvent::withoutGlobalScopes()->sole();
        $this->assertSame('incident_workflows:'.$incident->id, $usage->event_key);
        $this->assertSame($this->team->id, (int) $usage->team_id);
        $this->assertSame(1, (int) $usage->quantity);

        $lines = $this->systemLogEntries('incidents.usage.retried');
        $this->assertCount(2, $lines);
        $this->assertTrue($lines[0]['context']['result']['recorded']);
        $this->assertFalse($lines[1]['context']['result']['recorded']);
        $this->assertTrue($lines[1]['context']['result']['already_recorded']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_usage_retry_for_another_tenant_charges_nothing(): void
    {
        Bus::fake();
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);
        $other = Team::factory()->create();

        $this->assertNoTenantLeak(
            $other,
            fn () => app()->call([new RetryIncidentWorkflowUsageJob($incident->id, $other->id, [], now()->toIso8601String()), 'handle']),
        );

        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $c = $this->assertSystemLogged('incidents.usage.retry_skipped', fn (array $c) => $c['reason'] === 'incident_missing');
        $this->assertArrayNotHasKey('incident_id', $c['input']);
    }

    public function test_the_usage_is_recorded_once_per_incident(): void
    {
        Bus::fake();

        $incident = $this->openPanic($this->panicEvent());

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('event_key', 'incident_workflows:'.$incident->id)->count());
        $this->assertSystemLogged('incidents.incident.created', fn (array $c) => $c['result']['usage_recorded'] === true);
        $this->assertSystemNotLogged('incidents.usage.record_failed');
    }

    public function test_a_retried_reaction_never_duplicates_its_effects(): void
    {
        Bus::fake();

        $operator = User::factory()->create();
        $this->team->members()->attach($operator, ['role' => TeamRole::Member->value]);
        TenantScheduleProfile::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'timezone' => 'UTC',
            'shift_rules_json' => ['on_call' => [['user_id' => $operator->id]]],
        ]);

        $incident = $this->openPanic($this->panicEvent());

        foreach ([NotifyOnIncidentCreated::class, AssignOnCallOnIncidentCreated::class, StartCallVerificationOnIncidentCreated::class] as $reaction) {
            app()->call([new RetryIncidentCreatedReactionJob($reaction, $incident->id, $this->team->id, self::REACTIONS[$reaction]), 'handle']);
            app()->call([new RetryIncidentCreatedReactionJob($reaction, $incident->id, $this->team->id, self::REACTIONS[$reaction]), 'handle']);
        }

        $this->assertSame(1, Notification::withoutGlobalScopes()->where('event_key', 'incident_created:'.$incident->id)->count());
        $this->assertSame(1, Notification::withoutGlobalScopes()->where('event_key', 'incident_oncall_assigned:'.$incident->id)->count());
        $this->assertSame(1, IncidentAssignment::query()->where('incident_id', $incident->id)->count());
        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::VerificationCall)
            ->count(), 'La verificación (sin teléfono: escalada) no se repite.');
        $this->assertCount(6, $this->systemLogEntries('incidents.created_reaction.retried'));
    }

    public function test_the_retry_job_restores_the_incident_tenant_from_an_empty_context(): void
    {
        Queue::fake([RetryIncidentCreatedReactionJob::class]);
        Bus::fake();
        $incident = $this->openPanic($this->panicEvent());

        $seenTenant = null;
        $this->partialMock(NotifyOnIncidentCreated::class, function (MockInterface $mock) use (&$seenTenant) {
            $mock->shouldReceive('react')->once()->andReturnUsing(function (IncidentCreated $event) use (&$seenTenant) {
                $seenTenant = TenantContext::id();
            });
        });

        // El worker arranca cada job con el contexto vacío.
        Context::flush();

        app()->call([new RetryIncidentCreatedReactionJob(NotifyOnIncidentCreated::class, $incident->id, $this->team->id, 'notifications'), 'handle']);

        $this->assertSame($this->team->id, $seenTenant);
        $this->assertSystemLogged('incidents.created_reaction.retried', fn (array $c) => $c['input']['incident_id'] === $incident->id
            && $c['input']['reaction'] === 'NotifyOnIncidentCreated');
    }

    public function test_a_retry_job_for_another_tenant_touches_nothing(): void
    {
        Bus::fake();
        $incident = $this->openPanic($this->panicEvent());
        $other = Team::factory()->create();

        $this->partialMock(NotifyOnIncidentCreated::class, function (MockInterface $mock) {
            $mock->shouldReceive('react')->never();
        });

        $this->assertNoTenantLeak(
            $other,
            fn () => app()->call([new RetryIncidentCreatedReactionJob(NotifyOnIncidentCreated::class, $incident->id, $other->id, 'notifications'), 'handle']),
        );

        $c = $this->assertSystemLogged('incidents.created_reaction.retry_skipped', fn (array $c) => $c['reason'] === 'incident_missing');
        $this->assertArrayNotHasKey('incident_id', $c['input'], 'Un incidente de otro tenant no deja su id en el log.');
        $this->assertSystemNotLogged('incidents.created_reaction.retried');
    }

    public function test_the_retry_job_refuses_a_class_that_is_not_a_reaction(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);

        app()->call([new RetryIncidentCreatedReactionJob(User::class, $incident->id, $this->team->id, 'incidents'), 'handle']);

        $this->assertSystemLogged('incidents.created_reaction.retry_skipped', fn (array $c) => $c['reason'] === 'unknown_reaction');
    }

    public function test_every_reaction_retries_on_a_queue_production_consumes(): void
    {
        $consumed = [];

        foreach (config('horizon.defaults') as $supervisor) {
            array_push($consumed, ...$supervisor['queue']);
        }

        foreach (self::REACTIONS as $class => $queue) {
            $reaction = app($class);
            $this->assertInstanceOf(IncidentCreatedReaction::class, $reaction);
            $this->assertSame($queue, $reaction->retryQueue());
            $this->assertContains($queue, $consumed);
        }
    }
}
