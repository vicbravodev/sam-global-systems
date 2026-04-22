<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function createAssetForUser(User $user): Asset
    {
        $assetType = AssetType::factory()->vehicle()->create();

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Soft Delete Test Vehicle',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_it_soft_deletes_asset(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAssetForUser($user);

        $asset->delete();

        $this->assertSoftDeleted('assets', ['id' => $asset->id]);

        $trashedAsset = Asset::withoutGlobalScopes()->withTrashed()->find($asset->id);

        $this->assertNotNull(
            $trashedAsset,
            'Soft-deleted asset should still be retrievable via withTrashed()',
        );

        $this->assertNotNull(
            $trashedAsset->deleted_at,
            'Soft-deleted asset should have a deleted_at timestamp',
        );
    }

    public function test_it_excludes_soft_deleted_assets_from_queries(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAssetForUser($user);

        $asset->delete();

        $this->actingAs($user);

        $assets = Asset::all();

        $this->assertCount(
            0,
            $assets,
            'Default queries should exclude soft-deleted assets',
        );

        $allAssets = Asset::withTrashed()->get();

        $this->assertCount(
            1,
            $allAssets,
            'withTrashed() should include soft-deleted assets in the results',
        );
    }
}
