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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P1-10 / P1-11: deduplicación contra el último evento vinculado, candado de
 * creación por team+tipo+activo, correlador de ráfagas de device_offline y SLA
 * de eventos atrasados.
 */
class IncidentDedupAndBurstTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
    }

    public function test_late_event_sla_runs_from_now_not_from_when_it_happened(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $asset = Asset::factory()->create(['team_id' => $teamId]);

        $incident = $this->create($teamId, $asset->id, 'collision', minutesAgo: 600);

        $this->assertTrue($incident->sla_due_at->isFuture());
        $this->assertTrue($incident->opened_at->lt(now()->subHours(9)));
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn ($job) => $job->incidentId === $incident->id);
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
