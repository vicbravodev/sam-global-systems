<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\CommentVisibility;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentComment;
use App\Domains\Incidents\Models\IncidentEvidence;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncidentInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    public function test_inbox_renders_inertia_page_with_incident_rows(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Incident::factory()->count(3)->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(
            route('incidents.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('incidents/index')
                ->has('incidents', 3)
                ->has(
                    'incidents.0',
                    fn (Assert $row) => $row
                        ->where('incidentId', fn ($id) => is_int($id))
                        ->where('id', fn ($id) => str_starts_with((string) $id, 'INC-'))
                        ->hasAll([
                            'title',
                            'severity',
                            'status',
                            'provider',
                            'asset',
                            'driver',
                            'assignee',
                            'slaSeconds',
                            'slaTotal',
                            'ageMin',
                            'eventType',
                            'location',
                            'aiConfidence',
                            'aiDecision',
                            'aiReason',
                            'realtime',
                        ]),
                ),
        );
    }

    public function test_inbox_only_exposes_current_team_incidents(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $mine = Incident::factory()->count(2)->create(['team_id' => $team->id]);

        $other = User::factory()->create();
        $foreign = Incident::factory()->create(['team_id' => $other->currentTeam->id]);

        $response = $this->actingAs($user)->get(
            route('incidents.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($mine, $foreign) {
            $page->component('incidents/index')->has('incidents', 2);

            $ids = collect($page->toArray()['props']['incidents'])
                ->pluck('incidentId')
                ->all();

            $this->assertEqualsCanonicalizing($mine->pluck('id')->all(), $ids);
            $this->assertNotContains($foreign->id, $ids);
        });
    }

    public function test_inbox_maps_priority_status_and_assignment(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $critical = IncidentPriority::query()->firstOrCreate(
            ['code' => 'critical'],
            ['name' => 'Critical', 'level' => 4, 'sla_seconds' => 1800, 'color' => '#DC2626'],
        );
        $openStatus = IncidentStatus::query()->where('code', 'open')->firstOrFail();

        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $critical->id,
            'incident_status_id' => $openStatus->id,
        ]);

        $assignee = User::factory()->create(['name' => 'María Gómez']);
        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => AssigneeType::User,
            'assigned_to_id' => $assignee->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $response = $this->actingAs($user)->get(
            route('incidents.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('incidents/index')
                ->where('incidents.0.severity', 'critical')
                ->where('incidents.0.status', 'assigned')
                ->where('incidents.0.assignee.name', 'María Gómez')
                ->where('incidents.0.assignee.initials', 'MG')
                ->where('incidents.0.slaTotal', (int) $critical->sla_seconds),
        );
    }

    public function test_inbox_renders_array_location_from_normalized_payload(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $coordsEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'context_json' => null,
            'payload_normalized_json' => [
                'event_type' => 'harsh_brake',
                'location' => ['latitude' => 32.822863, 'longitude' => -85.212265],
            ],
        ]);
        $coordsIncident = Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $coordsEvent->id,
        ]);

        $addressEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'context_json' => null,
            'payload_normalized_json' => [
                'event_type' => 'harsh_brake',
                'location' => [
                    'latitude' => -32.889458,
                    'longitude' => -68.845839,
                    'formatted_location' => 'RN7 km 184 · Mendoza',
                ],
            ],
        ]);
        $addressIncident = Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $addressEvent->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('incidents.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($coordsIncident, $addressIncident) {
            $page->component('incidents/index')->has('incidents', 2);

            $locations = collect($page->toArray()['props']['incidents'])
                ->keyBy('incidentId')
                ->map(fn (array $row) => $row['location']);

            $this->assertSame('32.82286, -85.21227', $locations[$coordsIncident->id]);
            $this->assertSame('RN7 km 184 · Mendoza', $locations[$addressIncident->id]);
        });
    }

    public function test_show_returns_full_incident_detail_shape(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'context_json' => [
                'location' => 'RN7 km 184 · Mendoza',
                'weather' => 'Lluvia leve · 14 °C',
                'traffic' => 'Moderado',
                'driver_risk' => 58,
                'geofence_status' => 'Fuera',
                'driving_hours' => '4h 12m / 9h max',
            ],
        ]);

        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $event->id,
        ]);

        AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'classification' => EventClassification::RealEvent,
            'priority_level' => EvaluationPriority::Urgent,
            'confidence_score' => 0.87,
            'explanation_text' => 'Patrón coincide con colisiones confirmadas.',
            'model_used' => 'claude-sonnet-4-6',
            'evaluation_version' => 2,
        ]);

        IncidentTimeline::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Created,
            'title' => 'Incidente creado',
            'occurred_at' => now(),
        ]);

        IncidentComment::factory()->create([
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'comment' => 'Llamé al conductor.',
            'visibility' => CommentVisibility::Internal,
        ]);

        IncidentEvidence::factory()->create([
            'incident_id' => $incident->id,
            'evidence_type' => EvidenceType::TelemetrySnapshot,
            'title' => 'Snapshot telemetría',
        ]);

        $response = $this->actingAs($user)->getJson(
            route('incidents.show', ['current_team' => $team->slug, 'incident' => $incident->id]),
        );

        $response->assertOk();
        $response->assertJson([
            'incidentId' => $incident->id,
            'aiConfidence' => 0.87,
            'aiDecision' => 'escalate',
            'aiReason' => 'Patrón coincide con colisiones confirmadas.',
            'model' => 'claude-sonnet-4-6 · v2',
            'location' => 'RN7 km 184 · Mendoza',
            'operationalContext' => [
                'weather' => 'Lluvia leve · 14 °C',
                'traffic' => 'Moderado',
                'driverRisk' => 58,
                'geofenceStatus' => 'Fuera',
                'drivingHours' => '4h 12m / 9h max',
            ],
        ]);

        $data = $response->json();
        $this->assertCount(1, $data['timeline']);
        $this->assertSame('critical', $data['timeline'][0]['type']);
        $this->assertCount(1, $data['comments']);
        $this->assertSame('internal', $data['comments'][0]['visibility']);
        $this->assertCount(1, $data['evidence']);
        $this->assertSame('chart', $data['evidence'][0]['type']);
    }

    public function test_show_is_not_found_for_other_team_incident(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $other = User::factory()->create();
        $foreign = Incident::factory()->create(['team_id' => $other->currentTeam->id]);

        $response = $this->actingAs($user)->getJson(
            route('incidents.show', ['current_team' => $team->slug, 'incident' => $foreign->id]),
        );

        $response->assertNotFound();
    }
}
