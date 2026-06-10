<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssetsMapPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->create();

        $response = $this->get(route('assets.map', [
            'current_team' => $user->currentTeam->slug,
        ]));

        $response->assertRedirect(route('login'));
    }

    public function test_map_renders_only_positioned_assets_with_marker_shape(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        $positioned = Asset::factory()->critical()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Tractocamión Norte',
            'code' => 'TR-042',
        ]);
        $snapshot = AssetLocationSnapshot::factory()->create([
            'asset_id' => $positioned->id,
            'latitude' => 25.6866142,
            'longitude' => -100.3161126,
            'recorded_at' => now()->subMinutes(2),
        ]);

        // An asset with no location snapshots stays off the map.
        Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);

        $response = $this->actingAs($user)->get(route('assets.map', [
            'current_team' => $team->slug,
        ]));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('assets/map')
                ->has('assets', 1)
                ->has(
                    'assets.0',
                    fn (Assert $marker) => $marker
                        ->where('id', $positioned->id)
                        ->where('name', 'Tractocamión Norte')
                        ->where('code', 'TR-042')
                        ->where('status', 'critical')
                        ->where('category', 'vehicle')
                        ->where('latitude', 25.6866142)
                        ->where('longitude', -100.3161126)
                        ->where('recordedAt', $snapshot->recorded_at->toIso8601String())
                        ->has('speed')
                        ->has('heading'),
                )
                ->where('unpositionedCount', 1)
                ->has('statusLabels'),
        );
    }

    public function test_map_only_exposes_current_team_assets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $foreign = Asset::factory()->create([
            'team_id' => $other->currentTeam->id,
        ]);
        AssetLocationSnapshot::factory()->create(['asset_id' => $foreign->id]);

        $response = $this->actingAs($user)->get(route('assets.map', [
            'current_team' => $user->currentTeam->slug,
        ]));

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 0)
                ->where('unpositionedCount', 0),
        );
    }

    public function test_map_segment_does_not_collide_with_asset_binding(): void
    {
        $user = User::factory()->create();

        // If the literal "map" segment fell through to the {asset} binding it
        // would 404; the route order keeps it resolving to the map page.
        $response = $this->actingAs($user)->get(
            "/{$user->currentTeam->slug}/assets/map",
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page->component('assets/map'),
        );
    }
}
