<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scopes_assets_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $assetType = AssetType::factory()->vehicle()->create();

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $assetType->id,
            'name' => 'My Vehicle',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $otherUser = User::factory()->create();
        Asset::withoutGlobalScopes()->create([
            'team_id' => $otherUser->currentTeam->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Other Vehicle',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user);

        $assets = Asset::all();

        $this->assertCount(
            1,
            $assets,
            'BelongsToTenant scope should only return assets belonging to the current team',
        );

        $this->assertEquals(
            'My Vehicle',
            $assets->first()->name,
            'The returned asset should belong to the authenticated user\'s team',
        );
    }

    public function test_it_cannot_access_another_teams_assets(): void
    {
        $user = User::factory()->create();
        $assetType = AssetType::factory()->vehicle()->create();

        $otherUser = User::factory()->create();
        $otherAsset = Asset::withoutGlobalScopes()->create([
            'team_id' => $otherUser->currentTeam->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Foreign Vehicle',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user);

        $result = Asset::find($otherAsset->id);

        $this->assertNull(
            $result,
            'A user should not be able to find assets belonging to another team via Eloquent queries',
        );
    }
}
