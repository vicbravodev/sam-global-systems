<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    public function test_index_lists_incidents_for_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Incident::factory()->count(3)->create(['team_id' => $team->id]);

        $other = User::factory()->create();
        Incident::factory()->create(['team_id' => $other->currentTeam->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/incidents");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_show_returns_incident_with_relationships(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/incidents/{$incident->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame($incident->id, $data['id']);
        $this->assertArrayHasKey('timeline', $data);
        $this->assertArrayHasKey('evidence', $data);
        $this->assertArrayHasKey('event_links', $data);
    }

    public function test_show_returns_404_for_other_team_incident(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $other = User::factory()->create();
        $foreignIncident = Incident::factory()->create(['team_id' => $other->currentTeam->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/incidents/{$foreignIncident->id}");

        $response->assertStatus(404);
    }

    public function test_store_creates_manual_incident(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $type = IncidentType::query()->where('code', 'panic_emergency')->first();
        $priority = IncidentPriority::query()->where('code', 'high')->first();

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/incidents", [
            'incident_type_id' => $type->id,
            'incident_priority_id' => $priority->id,
            'title' => 'Manual incident',
            'summary' => 'Operator created this manually.',
        ]);

        $response->assertStatus(201);
        $this->assertSame('Manual incident', $response->json('data.title'));
        $this->assertDatabaseHas('incidents', [
            'team_id' => $team->id,
            'title' => 'Manual incident',
            'source_type' => 'manual',
            'created_by_id' => $user->id,
        ]);
    }

    public function test_store_validates_title_and_summary_required(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = IncidentType::query()->where('code', 'panic_emergency')->first();

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/incidents", [
            'incident_type_id' => $type->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'summary']);
    }

    public function test_assign_endpoint_creates_assignment(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/incidents/{$incident->id}/assign", [
            'assigned_to_type' => 'user',
            'assigned_to_id' => $user->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('incident_assignments', [
            'incident_id' => $incident->id,
            'assigned_to_id' => $user->id,
        ]);
    }

    public function test_resolve_endpoint_records_resolution(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/incidents/{$incident->id}/resolve", [
            'resolution_code' => 'handled_successfully',
            'summary' => 'Operator handled the incident',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('incident_resolutions', [
            'incident_id' => $incident->id,
            'resolution_code' => 'handled_successfully',
        ]);
    }

    public function test_cannot_modify_terminal_incident(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $closedStatus = IncidentStatus::query()->where('code', 'closed')->first();
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'incident_status_id' => $closedStatus->id,
        ]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/incidents/{$incident->id}/assign", [
            'assigned_to_type' => 'user',
            'assigned_to_id' => $user->id,
        ]);

        $response->assertStatus(403);
    }
}
