<?php

namespace Tests\Feature\Http;

use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavBadgesShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_receives_real_open_incident_count(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'member']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        Incident::factory()->count(3)->open()->create(['team_id' => $team->id]);
        Incident::factory()->resolved()->create(['team_id' => $team->id]);
        Incident::factory()->closed()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->get(route('dashboard', $team))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page->where('navBadges.inbox', 3),
            );
    }

    public function test_count_is_scoped_to_the_current_team(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $otherTeam = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'member']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        Incident::factory()->open()->create(['team_id' => $team->id]);
        Incident::factory()->count(4)->open()->create(['team_id' => $otherTeam->id]);

        $this->actingAs($user)
            ->get(route('dashboard', $team))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page->where('navBadges.inbox', 1),
            );
    }

    public function test_guest_does_not_receive_nav_badges(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page->where('navBadges', null),
            );
    }
}
