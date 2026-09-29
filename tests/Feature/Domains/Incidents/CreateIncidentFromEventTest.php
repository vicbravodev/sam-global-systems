<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentEvidence;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class CreateIncidentFromEventTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_creates_incident_with_correct_type_priority_and_links_event(): void
    {
        Event::fake([IncidentCreated::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);
        $driver = $this->makeDriver($team);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'driver_id' => $driver->id,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, [
            'incident_type_code' => 'panic_emergency',
            'priority_code' => 'critical',
            'decision_id' => 7,
        ]);

        $this->assertSame($team->id, $incident->team_id);
        $this->assertSame($event->id, $incident->related_event_id);
        $this->assertSame(IncidentSourceType::AiDecision, $incident->source_type);
        $this->assertSame($asset->id, $incident->asset_id);
        $this->assertSame($driver->id, $incident->driver_id);
        $this->assertSame('critical', $incident->fresh()->priority->code);
        $this->assertSame(IncidentStatusCode::Open->value, $incident->fresh()->status->code);
        $this->assertSame(7, $incident->related_decision_id);

        $this->assertDatabaseHas('incident_event_links', [
            'incident_id' => $incident->id,
            'normalized_event_id' => $event->id,
            'relation_type' => EventRelationType::RootTrigger->value,
        ]);

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Created->value,
        ]);

        Event::assertDispatched(IncidentCreated::class);

        $this->assertSystemLogged('incidents.incident.created', fn (array $c) => $c['result']['incident_id'] === $incident->id
            && $c['result']['priority_code'] === 'critical'
            && $c['result']['status_code'] === 'open'
            && $c['input']['decision_id'] === 7
            && $c['input']['source_type'] === IncidentSourceType::AiDecision->value
            && $c['calc']['dedup_checked'] === true
            && $c['calc']['dedup_window_minutes'] === 30
            && $c['calc']['offline_burst'] === null
            && $c['calc']['aggregate_burst'] === false
            && $c['result']['usage_event_key'] === 'incident_workflows:'.$incident->id);
        $this->assertSystemLogged('incidents.priority.resolved', fn (array $c) => $c['calc']['source'] === 'context_code'
            && $c['calc']['requested_code'] === 'critical'
            && $c['calc']['requested_found'] === true
            && $c['result']['priority_code'] === 'critical'
            && $c['result']['priority_level'] === 4);

        // Nunca el título ni el resumen del incidente.
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString($incident->title, $json);
        $this->assertStringNotContainsString('Creado automáticamente', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_rollback_logs_the_type_calculation_but_no_persisted_fact(): void
    {
        Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'));

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $this->makeAsset($team)->id,
        ]);

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'critical']);
            $this->fail('La creación debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('incidents.incident.created');
        $this->assertSystemNotLogged('incidents.sla.calculated');
        $this->assertSystemLogged('incidents.type.resolved');
    }

    public function test_does_not_create_duplicate_when_open_incident_exists_for_same_asset(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);

        $firstEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
        ]);

        $first = app(CreateIncidentFromEvent::class)->execute($firstEvent, [
            'incident_type_code' => 'collision',
        ]);

        $secondEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'occurred_at' => now()->addMinutes(5),
        ]);

        $second = app(CreateIncidentFromEvent::class)->execute($secondEvent, [
            'incident_type_code' => 'collision',
        ]);

        $this->assertSame($first->id, $second->id, 'Second event must reuse the existing open incident.');

        $this->assertSystemLogged('incidents.dedup.linked', fn (array $c) => $c['input']['normalized_event_id'] === $secondEvent->id
            && $c['calc']['matched_on'] === 'asset'
            && $c['calc']['match_basis'] === 'opened_in_window'
            && $c['calc']['window_minutes'] === 30
            && $c['result']['existing_incident_id'] === $first->id
            && $c['result']['link_created'] === true
            && $c['result']['priority_raised'] === false);
        $this->assertCount(1, $this->systemLogEntries('incidents.incident.created'));
        $this->assertNoSensitiveDataLogged();

        $this->assertSame(2, IncidentEventLink::query()->where('incident_id', $first->id)->count());
        $this->assertDatabaseHas('incident_event_links', [
            'incident_id' => $first->id,
            'normalized_event_id' => $secondEvent->id,
            'relation_type' => EventRelationType::SupportingEvent->value,
        ]);

        $this->assertSame(1, Incident::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_records_incident_workflows_usage_event(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event);

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', 'incident_workflows:'.$incident->id)
            ->count());
    }

    public function test_attaches_event_context_evidence_when_snapshot_exists(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
        ]);

        EventContextSnapshot::query()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'normalized_event_id' => $event->id,
            'event_occurred_at' => now(),
            'context_version' => 1,
            'location_snapshot_json' => [],
            'asset_snapshot_json' => [],
            'driver_snapshot_json' => [],
            'telemetry_snapshot_json' => [],
            'geofence_snapshot_json' => [],
            'incidents_snapshot_json' => [],
            'recent_history_snapshot_json' => [],
            'media_snapshot_json' => [],
            'signals_json' => [],
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event);

        $this->assertSame(1, IncidentEvidence::query()
            ->where('incident_id', $incident->id)
            ->where('evidence_type', EvidenceType::EventSnapshot->value)
            ->where('source_type', EvidenceSourceType::EventContext->value)
            ->count());
    }

    public function test_timeline_records_creation_with_payload(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, ['decision_id' => 22]);

        $created = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::Created->value)
            ->first();

        $this->assertNotNull($created);
        $this->assertSame(22, $created->payload_json['decision_id']);
        $this->assertSame($event->id, $created->payload_json['normalized_event_id']);
    }

    public function test_safety_event_without_matching_incident_type_resolves_to_its_category_bucket(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $category = EventCategory::factory()->create(['code' => 'safety']);
        $eventType = EventType::factory()->create(['code' => 'speeding', 'category_id' => $category->id]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_type_id' => $eventType->id,
            'event_category_id' => $category->id,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event);

        // A speeding event must NEVER masquerade as another incident type
        // (the old first-active-type fallback labeled these Panic Emergency).
        $this->assertSame('safety_violation', $incident->type->code);

        $c = $this->assertSystemLogged('incidents.type.resolved', fn (array $c) => $c['calc']['category_bucket_code'] === 'safety_violation'
            && $c['calc']['category_code'] === 'safety'
            && $c['calc']['event_type_code'] === 'speeding'
            && $c['calc']['used_last_resort'] === false);
        $this->assertSame('safety_violation', end($c['calc']['tried']));
        $this->assertSame(['speeding', 'safety_violation', 'other'], $c['calc']['candidates']);
        $this->assertSame('safety_violation', $c['result']['incident_type_code']);
    }

    public function test_panic_button_event_type_aliases_to_panic_emergency(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $category = EventCategory::factory()->create(['code' => 'emergency']);
        $eventType = EventType::factory()->create(['code' => 'panic_button', 'category_id' => $category->id]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_type_id' => $eventType->id,
            'event_category_id' => $category->id,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event);

        $this->assertSame('panic_emergency', $incident->type->code);

        $this->assertSystemLogged('incidents.type.resolved', fn (array $c) => $c['calc']['alias_code'] === 'panic_emergency'
            && $c['calc']['event_type_code'] === 'panic_button'
            && $c['result']['incident_type_code'] === 'panic_emergency');
    }

    public function test_sla_due_at_uses_tenant_override_when_present(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $critical = IncidentPriority::query()->where('code', 'critical')->firstOrFail();

        TenantIncidentSla::factory()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $critical->id,
            'sla_seconds' => 120,
        ]);

        // El SLA corre desde max(occurred_at, now()): con el reloj suelto, un
        // cambio de segundo entre crear el evento y el incidente lo desplaza.
        $this->freezeSecond();
        $occurredAt = now();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => $occurredAt,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, [
            'priority_code' => 'critical',
        ]);

        $this->assertNotNull($incident->sla_due_at);
        $this->assertSame(
            $occurredAt->copy()->addSeconds(120)->toIso8601String(),
            $incident->sla_due_at->toIso8601String(),
        );

        $c = $this->assertSystemLogged('incidents.sla.calculated', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc']['sla_source'] === 'tenant_override'
            && $c['calc']['sla_seconds'] === 120);
        $this->assertSlaRecomputes($c, $incident);
        $this->assertSame($incident->id, $c['input']['incident_id']);
        $this->assertTrue($c['result']['watchdog_requested']);
    }

    public function test_sla_due_at_falls_back_to_priority_catalog(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        // Catalog default for `critical` (IncidentPrioritySeeder): sla_seconds = 300.
        // El SLA corre desde max(occurred_at, now()): con el reloj suelto, un
        // cambio de segundo entre crear el evento y el incidente lo desplaza.
        $this->freezeSecond();
        $occurredAt = now();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => $occurredAt,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, [
            'priority_code' => 'critical',
        ]);

        $this->assertNotNull($incident->sla_due_at);
        $this->assertSame(
            $occurredAt->copy()->addSeconds(300)->toIso8601String(),
            $incident->sla_due_at->toIso8601String(),
        );

        $c = $this->assertSystemLogged('incidents.sla.calculated', fn (array $c) => $c['calc']['sla_source'] === 'priority_catalog'
            && $c['calc']['sla_seconds'] === 300);
        $this->assertSlaRecomputes($c, $incident);
        $this->assertSame('opened_at', $c['calc']['base_source']);
        $this->assertSame(0, $c['calc']['late_by_seconds']);
        $this->assertFalse($c['calc']['backfill_adjusted']);
    }

    public function test_priority_without_sla_logs_the_skip_and_arms_no_watchdog(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'low']);

        $this->assertNull($incident->sla_due_at);
        $this->assertSystemLogged('incidents.sla.calculated', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_sla_for_priority'
            && $c['calc']['sla_source'] === 'none'
            && $c['result']['watchdog_requested'] === false
            && $c['input']['incident_id'] === $incident->id);
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * sla_due_at = max(opened_at, now_at) + sla_seconds: se rehace con los
     * términos registrados y se compara con lo registrado y lo persistido.
     *
     * @param  array<string, mixed>  $c
     */
    private function assertSlaRecomputes(array $c, Incident $incident): void
    {
        $openedAt = Carbon::parse($c['calc']['opened_at']);
        $nowAt = Carbon::parse($c['calc']['now_at']);

        $this->assertSame($openedAt->copy()->max($nowAt)->toIso8601String(), $c['calc']['base_at']);

        $recomputed = Carbon::parse($c['calc']['base_at'])->addSeconds($c['calc']['sla_seconds'])->toIso8601String();
        $this->assertSame($recomputed, $c['result']['sla_due_at']);
        $this->assertSame($recomputed, $incident->fresh()->sla_due_at->toIso8601String());
    }

    public function test_unknown_event_type_resolves_to_the_generic_other_type(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $eventType = EventType::factory()->create(['code' => 'something_never_catalogued']);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_type_id' => $eventType->id,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event);

        $this->assertSame('other', $incident->type->code);

        $this->assertSystemLogged('incidents.type.resolved', fn (array $c) => $c['result']['incident_type_code'] === 'other'
            && $c['calc']['used_last_resort'] === false
            && $c['calc']['alias_code'] === null
            && $c['calc']['category_bucket_code'] === null);
    }

    private function makeAsset(Team $team): Asset
    {
        $type = AssetType::factory()->vehicle()->create();

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Truck '.fake()->bothify('?##'),
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function makeDriver(Team $team): Driver
    {
        return Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
