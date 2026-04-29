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
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreateIncidentFromEventTest extends TestCase
{
    use RefreshDatabase;

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
