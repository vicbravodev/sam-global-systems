<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P1-10 / P1-11: deduplicación contra el último evento vinculado, candado de
 * creación por team+tipo+activo, correlador de ráfagas de device_offline y SLA
 * de eventos atrasados.
 */
class IncidentDedupAndBurstTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private EventType $offline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
        Queue::fake();

        $this->offline = EventType::query()->where('code', DetectOfflineAssetsJob::EVENT_TYPE_CODE)->first()
            ?? EventType::factory()->create(['code' => DetectOfflineAssetsJob::EVENT_TYPE_CODE]);
    }

    public function test_a_steady_event_stream_keeps_one_incident_beyond_the_opening_window(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $asset = Asset::factory()->create(['team_id' => $teamId]);

        $first = $this->create($teamId, $asset->id, 'collision', minutesAgo: 80);
        $second = $this->create($teamId, $asset->id, 'collision', minutesAgo: 55);
        // 80 min después de abrir, pero sólo 25 min tras el último evento vinculado.
        $third = $this->create($teamId, $asset->id, 'collision', minutesAgo: 30);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, Incident::query()->where('team_id', $teamId)->count());

        $linked = $this->systemLogEntries('incidents.dedup.linked');
        $this->assertCount(2, $linked);
        $this->assertSame('opened_in_window', $linked[0]['context']['calc']['match_basis']);
        $third = end($linked)['context'];
        $this->assertSame('linked_event_in_window', $third['calc']['match_basis']);
        $this->assertSame($first->id, $third['result']['existing_incident_id']);
        // La apertura quedó fuera de la ventana: la sostiene el evento vinculado.
        $this->assertTrue($first->opened_at->lt(Carbon::parse($third['calc']['window_start'])));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_new_event_after_a_long_silence_opens_a_new_incident(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $asset = Asset::factory()->create(['team_id' => $teamId]);

        $old = $this->create($teamId, $asset->id, 'collision', minutesAgo: 240);

        Event::fake([IncidentCreated::class]);
        $new = $this->create($teamId, $asset->id, 'collision');

        $this->assertNotSame($old->id, $new->id);
        Event::assertDispatched(IncidentCreated::class);
    }

    public function test_concurrent_creation_for_the_same_team_type_and_asset_waits_on_the_lock(): void
    {
        config(['incidents.dedup_lock_wait_seconds' => 0]);

        $teamId = User::factory()->create()->currentTeam->id;
        $asset = Asset::factory()->create(['team_id' => $teamId]);
        $event = $this->event($teamId, $asset->id);
        $typeId = IncidentType::query()->where('code', 'collision')->value('id');

        // Otro worker está creando el incidente de ESTE team+tipo+activo.
        $held = Cache::lock("incident_dedup:{$teamId}:{$typeId}:a{$asset->id}:d", 30);
        $this->assertTrue($held->get());

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'collision']);
            $this->fail('La creación debía esperar el candado.');
        } catch (LockTimeoutException) {
            $this->assertSame(0, Incident::query()->where('team_id', $teamId)->count());
        } finally {
            $held->release();
        }

        // El mismo activo en OTRO team no comparte candado.
        $otherTeamId = User::factory()->create()->currentTeam->id;
        $otherAsset = Asset::factory()->create(['team_id' => $otherTeamId]);
        $this->assertNotNull($this->create($otherTeamId, $otherAsset->id, 'collision'));
    }

    public function test_the_fast_path_and_the_ai_path_of_an_unresolved_asset_event_never_open_two_incidents(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        // Pánico de una unidad que no se pudo resolver: ni activo ni conductor.
        $event = NormalizedEvent::factory()->create([
            'team_id' => $teamId,
            'asset_id' => null,
            'driver_id' => null,
            'occurred_at' => now(),
        ]);

        // Con backlog, OpenEmergencyIncidentJob y CreateIncidentJob pasan los
        // dos su chequeo previo (fuera del candado) y llegan a la Action.
        $fast = app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'panic_emergency', 'priority_code' => 'critical']);
        $ai = app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'collision', 'priority_code' => 'medium']);

        $this->assertSame($fast->id, $ai->id);
        $this->assertSame(1, Incident::query()->where('team_id', $teamId)->count());

        $c = $this->assertSystemLogged('incidents.dedup.same_event');
        $this->assertSame('ok', $c['outcome']);
        $this->assertSame($event->id, $c['input']['normalized_event_id']);
        $this->assertSame($fast->id, $c['result']['existing_incident_id']);
        $this->assertNoSensitiveDataLogged();

        // Otro evento sin activo del mismo team sí abre su propio incidente.
        $other = NormalizedEvent::factory()->create(['team_id' => $teamId, 'asset_id' => null, 'driver_id' => null, 'occurred_at' => now()]);
        $this->assertNotSame($fast->id, app(CreateIncidentFromEvent::class)->execute($other, ['incident_type_code' => 'panic_emergency'])->id);
    }

    public function test_an_event_without_asset_or_driver_waits_on_its_own_lock(): void
    {
        config(['incidents.dedup_lock_wait_seconds' => 0]);

        $teamId = User::factory()->create()->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId, 'asset_id' => null, 'driver_id' => null, 'occurred_at' => now()]);

        // El otro camino del mismo evento está creando el incidente.
        $held = Cache::lock("incident_dedup:{$teamId}:event:{$event->id}", 30);
        $this->assertTrue($held->get());

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'panic_emergency']);
            $this->fail('La creación debía esperar el candado del evento.');
        } catch (LockTimeoutException) {
            $this->assertSame(0, Incident::query()->where('team_id', $teamId)->count());
        } finally {
            $held->release();
        }
    }

    public function test_an_unresolved_asset_event_of_another_tenant_is_never_taken_as_the_same_event(): void
    {
        $teamA = User::factory()->create()->currentTeam;
        $teamB = User::factory()->create()->currentTeam;
        $eventB = NormalizedEvent::factory()->create(['team_id' => $teamB->id, 'asset_id' => null, 'driver_id' => null, 'occurred_at' => now()]);
        app(CreateIncidentFromEvent::class)->execute($eventB, ['incident_type_code' => 'panic_emergency']);

        $eventA = NormalizedEvent::factory()->create(['team_id' => $teamA->id, 'asset_id' => null, 'driver_id' => null, 'occurred_at' => now()]);

        $incident = $this->assertNoTenantLeak($teamA, fn () => app(CreateIncidentFromEvent::class)
            ->execute($eventA, ['incident_type_code' => 'panic_emergency']));

        $this->assertSame($teamA->id, (int) $incident->team_id);
        $this->assertSame([], $this->systemLogEntries('incidents.dedup.same_event'));
    }

    public function test_device_offline_burst_collapses_into_one_aggregated_incident(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $assets = Asset::factory()->count(5)->create(['team_id' => $teamId]);

        Event::fake([IncidentCreated::class]);

        $incidents = $assets->map(fn (Asset $asset) => $this->createOffline($teamId, $asset->id));

        // Los dos primeros son individuales; el tercero abre el agregado y los
        // siguientes se vinculan a él: 3 incidentes (3 avisos) en vez de 5.
        $this->assertSame(3, $incidents->pluck('id')->unique()->count());

        $aggregate = Incident::query()->where('team_id', $teamId)->whereNull('asset_id')->sole();
        $this->assertSame('Varios dispositivos sin conexión', $aggregate->title);
        $this->assertSame($aggregate->id, $incidents[3]->id);
        $this->assertSame($aggregate->id, $incidents[4]->id);
        $this->assertSame(3, $aggregate->eventLinks()->count());
        Event::assertDispatchedTimes(IncidentCreated::class, 3);

        $opened = $this->assertSystemLogged('incidents.offline_burst.aggregated', fn (array $c) => $c['calc']['branch'] === 'opened_aggregate');
        $this->assertSame($aggregate->id, $opened['result']['aggregate_incident_id']);
        // link_created sale del vínculo RootTrigger real, no de una constante.
        $this->assertTrue($opened['result']['link_created']);
        $this->assertTrue($aggregate->eventLinks()->where('relation_type', 'root_trigger')->exists());
        $this->assertSame(2, $opened['calc']['recent_singles_count']);
        $this->assertSame(3, $opened['calc']['effective_threshold']);
        $this->assertFalse($opened['calc']['aggregate_found']);
        $this->assertTrue($opened['calc']['recent_singles_count'] + 1 >= $opened['calc']['effective_threshold']);
        $this->assertSame(max(2, $opened['calc']['configured_threshold']), $opened['calc']['effective_threshold']);
        $this->assertSame(CreateIncidentFromEvent::OFFLINE_BURST_WINDOW_MINUTES, $opened['calc']['window_minutes']);

        $linked = array_values(array_filter(
            $this->systemLogEntries('incidents.offline_burst.aggregated'),
            fn (array $e) => $e['context']['calc']['branch'] === 'linked_to_aggregate',
        ));
        $this->assertCount(2, $linked);
        foreach ($linked as $entry) {
            $this->assertSame($aggregate->id, $entry['context']['result']['aggregate_incident_id']);
            $this->assertTrue($entry['context']['calc']['aggregate_found']);
            $this->assertNull($entry['context']['calc']['recent_singles_count']);
        }

        $created = $this->systemLogEntries('incidents.incident.created');
        $this->assertCount(3, $created);
        $this->assertSame('below_threshold', $created[0]['context']['calc']['offline_burst']['branch']);
        $this->assertSame(0, $created[0]['context']['calc']['offline_burst']['recent_singles_count']);
        $this->assertSame('below_threshold', $created[1]['context']['calc']['offline_burst']['branch']);
        $this->assertSame(1, $created[1]['context']['calc']['offline_burst']['recent_singles_count']);
        $this->assertTrue($created[2]['context']['calc']['aggregate_burst']);
        $this->assertSame('opened_aggregate', $created[2]['context']['calc']['offline_burst']['branch']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_offline_burst_of_another_tenant_never_absorbs_this_tenants_event(): void
    {
        $teamA = User::factory()->create()->currentTeam->id;
        foreach (Asset::factory()->count(3)->create(['team_id' => $teamA]) as $asset) {
            $this->createOffline($teamA, $asset->id);
        }

        $teamB = User::factory()->create()->currentTeam;
        $assetB = Asset::factory()->create(['team_id' => $teamB->id]);

        $eventB = $this->offlineEvent($teamB->id, $assetB->id);

        $incidentB = $this->assertNoTenantLeak(
            $teamB,
            fn () => app(CreateIncidentFromEvent::class)->execute($eventB, ['incident_type_code' => 'operational_alert']),
        );

        $this->assertSame($assetB->id, $incidentB->asset_id);

        $teamAIncidentIds = Incident::withoutGlobalScopes()->where('team_id', $teamA)->pluck('id')->all();
        foreach ($this->systemLogEntries('incidents.offline_burst.aggregated') as $entry) {
            if ($entry['context']['input']['normalized_event_id'] === $eventB->id) {
                $this->assertNotContains($entry['context']['result']['aggregate_incident_id'], $teamAIncidentIds);
            }
        }
        $this->assertSystemLogged('incidents.incident.created', fn (array $c) => $c['result']['incident_id'] === $incidentB->id
            && $c['calc']['offline_burst']['branch'] === 'below_threshold'
            && $c['calc']['offline_burst']['recent_singles_count'] === 0);
    }

    public function test_late_event_sla_runs_from_now_not_from_when_it_happened(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $asset = Asset::factory()->create(['team_id' => $teamId]);

        $incident = $this->create($teamId, $asset->id, 'collision', minutesAgo: 600);

        $this->assertTrue($incident->sla_due_at->isFuture());
        $this->assertTrue($incident->opened_at->lt(now()->subHours(9)));
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn ($job) => $job->incidentId === $incident->id);

        $c = $this->assertSystemLogged('incidents.sla.calculated', fn (array $c) => $c['input']['incident_id'] === $incident->id);
        $this->assertSame('now', $c['calc']['base_source']);
        $this->assertGreaterThanOrEqual(36000, $c['calc']['late_by_seconds']);
        $this->assertTrue($c['calc']['backfill_adjusted']);
        $this->assertSame($c['calc']['now_at'], $c['calc']['base_at']);
        $this->assertSame(
            Carbon::parse($c['calc']['base_at'])->addSeconds($c['calc']['sla_seconds'])->toIso8601String(),
            $c['result']['sla_due_at'],
        );
        $this->assertSame($incident->sla_due_at->toIso8601String(), $c['result']['sla_due_at']);
    }

    private function create(int $teamId, int $assetId, string $typeCode, int $minutesAgo = 0, ?NormalizedEvent $event = null): Incident
    {
        $event ??= $this->event($teamId, $assetId, $minutesAgo);

        return app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => $typeCode]);
    }

    private function createOffline(int $teamId, int $assetId): Incident
    {
        return app(CreateIncidentFromEvent::class)->execute(
            $this->offlineEvent($teamId, $assetId),
            ['incident_type_code' => 'operational_alert'],
        );
    }

    private function offlineEvent(int $teamId, int $assetId): NormalizedEvent
    {
        return NormalizedEvent::factory()->create([
            'team_id' => $teamId,
            'asset_id' => $assetId,
            'event_type_id' => $this->offline->id,
            'occurred_at' => now(),
        ]);
    }

    private function event(int $teamId, int $assetId, int $minutesAgo = 0): NormalizedEvent
    {
        return NormalizedEvent::factory()->create([
            'team_id' => $teamId,
            'asset_id' => $assetId,
            'occurred_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
