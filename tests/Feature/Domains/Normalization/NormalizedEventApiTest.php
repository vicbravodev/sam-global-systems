<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizedEventApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_show_returns_the_event_of_the_current_team(): void
    {
        // Antes la firma no declaraba `Team $current_team`, así que el slug
        // caía en el argumento del modelo y la ruta respondía 500.
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->getJson("/api/{$team->slug}/events/normalized/{$event->id}")
            ->assertOk()
            ->assertJsonPath('id', $event->id);
    }
}
