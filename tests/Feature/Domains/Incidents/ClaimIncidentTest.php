<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\ClaimIncident;
use App\Domains\Incidents\Actions\ReleaseIncident;
use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_claimer_wins_and_second_is_rejected(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = User::factory()->create(['current_team_id' => $team->id]);
        $beto = User::factory()->create(['current_team_id' => $team->id]);

        $claim = app(ClaimIncident::class);

        $this->assertTrue($claim->execute($incident, $ana));
        $this->assertFalse($claim->execute($incident->fresh(), $beto));

        $this->assertSame($ana->id, $incident->fresh()->claimed_by_user_id);
        $this->assertNotNull($incident->fresh()->claimed_at);
    }

    public function test_reclaiming_by_the_same_user_is_allowed(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = User::factory()->create(['current_team_id' => $team->id]);

        $claim = app(ClaimIncident::class);

        $this->assertTrue($claim->execute($incident, $ana));
        $this->assertTrue($claim->execute($incident->fresh(), $ana));
    }

    public function test_only_the_owner_can_release(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = User::factory()->create(['current_team_id' => $team->id]);
        $beto = User::factory()->create(['current_team_id' => $team->id]);

        app(ClaimIncident::class)->execute($incident, $ana);
        $release = app(ReleaseIncident::class);

        $this->assertFalse($release->execute($incident->fresh(), $beto));
        $this->assertTrue($release->execute($incident->fresh(), $ana));
        $this->assertNull($incident->fresh()->claimed_by_user_id);
    }
}
