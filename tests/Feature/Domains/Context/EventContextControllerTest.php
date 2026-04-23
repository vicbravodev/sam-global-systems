<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventContextControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_returns_snapshot_for_team_member(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        app(BuildEventContext::class)->execute($event);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/events/{$event->id}/context");

        $response->assertOk();
        $this->assertSame($team->id, $response->json('data.team_id'));
        $this->assertArrayHasKey('profile', $response->json('data'));
    }

    public function test_returns_404_when_snapshot_missing(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/events/{$event->id}/context");

        $response->assertNotFound();
    }

    public function test_blocks_access_across_teams(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $event = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        app(BuildEventContext::class)->execute($event);

        $this->actingAs($userB);

        $response = $this->getJson("/api/{$userA->currentTeam->slug}/events/{$event->id}/context");

        $this->assertContains($response->status(), [403, 404]);
    }
}
