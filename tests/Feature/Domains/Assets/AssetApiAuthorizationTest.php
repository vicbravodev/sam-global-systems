<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La api de activos y eventos no puede depender sólo del scope global: cada
 * endpoint autoriza con una Policy que compara el team explícitamente, y los
 * listados filtran por el team de la ruta.
 */
class AssetApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Team $otherTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->otherTeam = Team::factory()->create();
    }

    public function test_foreign_asset_endpoints_are_not_reachable(): void
    {
        $foreign = Asset::factory()->create(['team_id' => $this->otherTeam->id]);

        foreach (['', '/location-history', '/telemetry'] as $suffix) {
            $response = $this->actingAs($this->user)->getJson("/api/{$this->team->slug}/assets/{$foreign->id}{$suffix}");

            $this->assertContains($response->status(), [403, 404], "assets/{id}{$suffix}");
        }
    }

    public function test_asset_policy_compares_the_team_explicitly(): void
    {
        $foreign = Asset::factory()->create(['team_id' => $this->otherTeam->id]);
        $own = Asset::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user);

        $this->assertFalse($this->user->can('view', $foreign));
        $this->assertTrue($this->user->can('view', $own));
    }

    public function test_own_asset_endpoints_work(): void
    {
        $own = Asset::factory()->create(['team_id' => $this->team->id]);

        foreach (['', '/location-history', '/telemetry'] as $suffix) {
            $this->actingAs($this->user)
                ->getJson("/api/{$this->team->slug}/assets/{$own->id}{$suffix}")
                ->assertOk();
        }
    }

    public function test_event_endpoints_only_return_the_current_team(): void
    {
        NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        NormalizedEvent::factory()->unmapped()->create(['team_id' => $this->otherTeam->id]);
        $foreign = NormalizedEvent::factory()->create(['team_id' => $this->otherTeam->id]);
        RawEvent::factory()->create(['team_id' => $this->otherTeam->id]);

        $this->actingAs($this->user);

        $this->assertCount(1, $this->getJson("/api/{$this->team->slug}/events/normalized")->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson("/api/{$this->team->slug}/normalization/unmapped")->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson("/api/{$this->team->slug}/events/raw")->assertOk()->json('data'));

        $this->assertContains(
            $this->getJson("/api/{$this->team->slug}/events/normalized/{$foreign->id}")->status(),
            [403, 404],
        );

        $this->assertFalse($this->user->can('view', $foreign));
    }

    public function test_event_endpoints_require_permission(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson("/api/{$this->team->slug}/events/raw")
            ->assertForbidden();
    }
}
