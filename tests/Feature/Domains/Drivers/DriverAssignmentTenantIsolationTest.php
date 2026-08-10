<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Models\DriverAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAssignmentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_from_other_teams_are_not_visible(): void
    {
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;

        $userB = User::factory()->create();
        $teamB = $userB->currentTeam;

        DriverAssignment::factory()->count(2)->create(['team_id' => $teamA->id]);
        DriverAssignment::factory()->count(3)->create(['team_id' => $teamB->id]);

        $this->actingAs($userA);
        $userA->switchTeam($teamA);

        $visible = DriverAssignment::query()->get();

        $this->assertCount(2, $visible);
        $this->assertTrue($visible->every(fn ($a) => $a->team_id === $teamA->id));
    }

    public function test_created_assignment_inherits_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);
        $user->switchTeam($team);

        $assignment = DriverAssignment::factory()->create(['team_id' => null]);

        $this->assertSame($team->id, $assignment->fresh()->team_id);
    }
}
