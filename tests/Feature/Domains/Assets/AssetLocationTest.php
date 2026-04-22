<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\UpdateAssetLocationSnapshot;
use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Events\AssetLocationUpdated;
use App\Domains\Assets\Events\AssetLocationUpdatedBroadcast;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssetLocationTest extends TestCase
{
    use RefreshDatabase;

    private function createAssetWithUser(): array
    {
        $user = User::factory()->create();
        $assetType = AssetType::factory()->vehicle()->create();

        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Location Test Vehicle',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [$user, $asset];
    }

    public function test_it_records_location_snapshot_for_asset(): void
    {
        Event::fake([AssetLocationUpdated::class, AssetLocationUpdatedBroadcast::class]);

        [, $asset] = $this->createAssetWithUser();

        $action = app(UpdateAssetLocationSnapshot::class);
        $snapshot = $action->execute(
            asset: $asset,
            latitude: 19.4326077,
            longitude: -99.1332080,
            source: LocationSource::Provider,
            speed: 65.5,
            heading: 180,
        );

        $this->assertNotNull(
            $snapshot->id,
            'Location snapshot should be persisted with a valid ID',
        );

        $this->assertEquals(
            $asset->id,
            $snapshot->asset_id,
            'Location snapshot should be associated with the correct asset',
        );

        $this->assertTrue(
            AssetLocationSnapshot::where('asset_id', $asset->id)
                ->where('source', LocationSource::Provider->value)
                ->exists(),
            'Location snapshot should be stored in the database',
        );

        $asset->refresh();
        $this->assertNotNull(
            $asset->last_seen_at,
            'Asset last_seen_at should be updated after recording a location',
        );
    }

    public function test_it_dispatches_asset_location_updated_event(): void
    {
        Event::fake([AssetLocationUpdated::class, AssetLocationUpdatedBroadcast::class]);

        [, $asset] = $this->createAssetWithUser();

        $action = app(UpdateAssetLocationSnapshot::class);
        $action->execute(
            asset: $asset,
            latitude: 25.6866,
            longitude: -100.3161,
            source: LocationSource::Gps,
        );

        Event::assertDispatched(AssetLocationUpdated::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && abs($event->latitude - 25.6866) < 0.001
                && abs($event->longitude - (-100.3161)) < 0.001;
        });
    }

    public function test_it_broadcasts_asset_location_updated(): void
    {
        Event::fake([AssetLocationUpdated::class, AssetLocationUpdatedBroadcast::class]);

        [, $asset] = $this->createAssetWithUser();

        $action = app(UpdateAssetLocationSnapshot::class);
        $action->execute(
            asset: $asset,
            latitude: 20.6597,
            longitude: -103.3496,
            source: LocationSource::Provider,
        );

        Event::assertDispatched(AssetLocationUpdatedBroadcast::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && $event->teamId === $asset->team_id;
        });
    }

    public function test_it_returns_paginated_location_history(): void
    {
        [$user, $asset] = $this->createAssetWithUser();
        $team = $user->currentTeam;

        AssetLocationSnapshot::factory()->count(25)->create([
            'asset_id' => $asset->id,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/assets/{$asset->id}/location-history?per_page=10");

        $response->assertOk();

        $this->assertCount(
            10,
            $response->json('data'),
            'Location history should return the requested page size of 10 items',
        );

        $this->assertNotNull(
            $response->json('next_cursor'),
            'Response should include a cursor for pagination when more records exist',
        );
    }
}
