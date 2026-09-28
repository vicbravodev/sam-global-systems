<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing in the pipeline writes `normalized_events.context_json`, so the
 * operational-context card always read "—". The presenter now reads the
 * snapshot BuildEventContext persists in `event_context_snapshots`.
 */
class IncidentOperationalContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    public function test_detail_reads_geofence_and_driver_risk_from_the_context_snapshot(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id, 'context_json' => null]);
        EventContextSnapshot::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'driver_snapshot_json' => ['risk_profile' => ['risk_score' => 71.6]],
            'geofence_snapshot_json' => [
                ['geofence_id' => 2, 'name' => 'Zona de riesgo', 'match_type' => 'near_boundary'],
                ['geofence_id' => 1, 'name' => 'Base Querétaro', 'match_type' => 'inside'],
            ],
        ]);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'related_event_id' => $event->id]);

        $this->actingAs($user)
            ->getJson(route('incidents.show', ['current_team' => $team->slug, 'incident' => $incident->id]))
            ->assertOk()
            ->assertJsonPath('operationalContext.driverRisk', 72)
            ->assertJsonPath('operationalContext.geofenceStatus', 'Dentro de Base Querétaro');
    }

    public function test_snapshot_without_geofence_matches_reads_outside(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        EventContextSnapshot::factory()->create(['team_id' => $team->id, 'normalized_event_id' => $event->id]);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'related_event_id' => $event->id]);

        $this->actingAs($user)
            ->getJson(route('incidents.show', ['current_team' => $team->slug, 'incident' => $incident->id]))
            ->assertJsonPath('operationalContext.geofenceStatus', 'Fuera de geocercas')
            ->assertJsonPath('operationalContext.driverRisk', 0);
    }

    public function test_without_snapshot_the_context_stays_empty(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'related_event_id' => $event->id]);

        $this->actingAs($user)
            ->getJson(route('incidents.show', ['current_team' => $team->slug, 'incident' => $incident->id]))
            ->assertJsonPath('operationalContext', [
                'weather' => '—',
                'traffic' => '—',
                'driverRisk' => 0,
                'geofenceStatus' => '—',
                'drivingHours' => '—',
            ]);
    }

    public function test_a_snapshot_stamped_with_another_tenant_is_ignored(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $otherTeam = User::factory()->create()->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        EventContextSnapshot::factory()->create([
            'team_id' => $otherTeam->id,
            'normalized_event_id' => $event->id,
            'driver_snapshot_json' => ['risk_profile' => ['risk_score' => 90]],
            'geofence_snapshot_json' => [['geofence_id' => 9, 'name' => 'Ajena', 'match_type' => 'inside']],
        ]);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'related_event_id' => $event->id]);

        $this->actingAs($user)
            ->getJson(route('incidents.show', ['current_team' => $team->slug, 'incident' => $incident->id]))
            ->assertJsonPath('operationalContext.geofenceStatus', '—')
            ->assertJsonPath('operationalContext.driverRisk', 0);
    }
}
