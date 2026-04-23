<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpersTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_team_returns_null_when_no_authenticated_user(): void
    {
        $this->assertNull(currentTeam());
    }

    public function test_current_team_returns_the_authenticated_users_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $this->assertNotNull(currentTeam());
        $this->assertEquals($team->id, currentTeam()->id);
    }
}
