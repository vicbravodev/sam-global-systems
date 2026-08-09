<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Domains\Notifications\Actions\SendNotification;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HumanControlSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
    }

    public function test_claimed_incident_is_under_human_control(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $this->assertTrue(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_acknowledged_incident_is_under_human_control(): void
    {
        $incident = Incident::factory()->create(['acknowledged_at' => now()]);

        $this->assertTrue(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_untouched_incident_is_not_under_human_control(): void
    {
        $incident = Incident::factory()->create([
            'claimed_by_user_id' => null,
            'claimed_at' => null,
            'acknowledged_at' => null,
        ]);

        $this->assertFalse(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_escalation_watchdog_stops_when_incident_is_claimed(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'sla_due_at' => now()->subMinutes(5),
            'acknowledged_at' => null,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        (new CheckIncidentAcknowledgementJob($incident->id, 1, 1))->handle(
            app(EscalateIncident::class),
            app(AppendTimelineEntry::class),
            app(SendNotification::class),
        );

        $this->assertDatabaseMissing('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => 'sla_breached',
        ]);
    }
}
