<?php

namespace Tests\Feature\Http\Middleware;

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureTeamMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', EnsureTeamMembership::class])
            ->get('/__test/teams/{current_team}', fn (Team $current_team) => response()->json(['team' => $current_team->slug]))
            ->name('__test.teams.default');

        Route::middleware(['web', 'auth', EnsureTeamMembership::class.':admin'])
            ->get('/__test/teams/{current_team}/admin', fn (Team $current_team) => response()->json(['team' => $current_team->slug]))
            ->name('__test.teams.admin');

        Route::middleware(['web', 'auth', EnsureTeamMembership::class])
            ->get('/__test/bare', fn () => response()->json(['ok' => true]))
            ->name('__test.bare');
    }

    public function test_it_allows_members_of_the_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->getJson("/__test/teams/{$team->slug}");

        $response->assertOk();
        $response->assertJson(['team' => $team->slug]);
    }

    public function test_it_aborts_when_user_is_not_a_member(): void
    {
        $user = User::factory()->create();
        $otherTeam = Team::factory()->create();

        $response = $this->actingAs($user)->getJson("/__test/teams/{$otherTeam->slug}");

        $response->assertForbidden();
    }

    public function test_it_aborts_when_no_team_can_be_resolved_from_the_route(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/__test/bare');

        $response->assertForbidden();
    }

    public function test_it_switches_current_team_when_route_team_differs(): void
    {
        $user = User::factory()->create();
        $originalTeam = $user->currentTeam;

        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

        $response = $this->actingAs($user)->getJson("/__test/teams/{$otherTeam->slug}");

        $response->assertOk();

        $user->refresh();

        $this->assertEquals($otherTeam->id, $user->current_team_id);
        $this->assertNotEquals($originalTeam->id, $user->current_team_id);
    }

    public function test_it_enforces_minimum_role_when_specified(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        $response = $this->actingAs($user)->getJson("/__test/teams/{$team->slug}/admin");

        $response->assertForbidden();
    }

    public function test_it_passes_when_user_meets_minimum_role(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

        $response = $this->actingAs($user)->getJson("/__test/teams/{$team->slug}/admin");

        $response->assertOk();
    }

    public function test_it_aborts_when_minimum_role_value_is_invalid(): void
    {
        Route::middleware(['web', 'auth', EnsureTeamMembership::class.':nonexistent-role'])
            ->get('/__test/teams/{current_team}/bogus', fn (Team $current_team) => response()->json([]))
            ->name('__test.teams.bogus');

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->getJson("/__test/teams/{$team->slug}/bogus");

        $response->assertForbidden();
    }
}
