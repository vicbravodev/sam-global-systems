<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Models\Driver;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_it_scopes_drivers_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Team',
            'last_name' => 'Driver',
            'full_name' => 'Team Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $otherTeam = Team::factory()->create();
        Driver::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'first_name' => 'Other',
            'last_name' => 'Driver',
            'full_name' => 'Other Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/drivers");

        $response->assertOk();

        $this->assertCount(
            1,
            $response->json('data'),
            'Driver listing should only return drivers belonging to the current team',
        );

        $this->assertEquals(
            'Team Driver',
            $response->json('data.0.full_name'),
            'The returned driver should be the one belonging to the current team',
        );
    }

    public function test_it_cannot_access_another_teams_drivers(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $otherTeam = Team::factory()->create();
        $otherDriver = Driver::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'first_name' => 'Foreign',
            'last_name' => 'Driver',
            'full_name' => 'Foreign Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/drivers/{$otherDriver->id}");

        $this->assertTrue(
            in_array($response->status(), [404, 403]),
            'Accessing another team\'s driver should return 404 or 403, got '.$response->status(),
        );
    }
}
