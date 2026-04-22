<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    private function createSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $vehicleType = AssetType::factory()->vehicle()->create();
        $cameraType = AssetType::factory()->camera()->create();

        return [$user, $team, $vehicleType, $cameraType];
    }

    public function test_it_lists_assets_with_filters(): void
    {
        [$user, $team, $vehicleType, $cameraType] = $this->createSetup();

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Active Truck',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Offline Truck',
            'status' => AssetStatus::Offline,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $cameraType->id,
            'name' => 'Active Camera',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/assets");
        $response->assertOk();
        $this->assertCount(
            3,
            $response->json('data'),
            'Unfiltered asset list should return all 3 assets for the team',
        );

        $response = $this->getJson("/api/{$team->slug}/assets?status=active");
        $response->assertOk();
        $this->assertCount(
            2,
            $response->json('data'),
            'Filtering by status=active should return only the 2 active assets',
        );

        $response = $this->getJson("/api/{$team->slug}/assets?type=camera");
        $response->assertOk();
        $this->assertCount(
            1,
            $response->json('data'),
            'Filtering by type=camera should return only the 1 camera asset',
        );

        $response = $this->getJson("/api/{$team->slug}/assets?search=Truck");
        $response->assertOk();
        $this->assertCount(
            2,
            $response->json('data'),
            'Searching for "Truck" should return both truck assets',
        );
    }

    public function test_it_shows_asset_detail_with_devices_and_location(): void
    {
        [$user, $team, $vehicleType] = $this->createSetup();

        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Detail Truck',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        AssetDevice::factory()->create(['asset_id' => $asset->id]);

        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/assets/{$asset->id}");

        $response->assertOk();

        $data = $response->json('data');

        $this->assertEquals(
            $asset->id,
            $data['id'],
            'Show endpoint should return the requested asset',
        );

        $this->assertArrayHasKey(
            'asset_type',
            $data,
            'Asset detail should include the eager-loaded asset type relationship',
        );

        $this->assertArrayHasKey(
            'devices',
            $data,
            'Asset detail should include the eager-loaded devices relationship',
        );

        $this->assertArrayHasKey(
            'latest_location',
            $data,
            'Asset detail should include the eager-loaded latest location',
        );
    }

    public function test_it_rejects_manual_asset_creation(): void
    {
        [$user, $team] = $this->createSetup();

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/assets", [
            'name' => 'Manual Asset',
        ]);

        $this->assertTrue(
            in_array($response->status(), [404, 405]),
            'POST to /assets should be rejected (404 or 405) since assets cannot be created manually',
        );
    }

    public function test_it_returns_telemetry_snapshots_filtered_by_type(): void
    {
        [$user, $team, $vehicleType] = $this->createSetup();

        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Telemetry Truck',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        AssetTelemetrySnapshot::factory()->speed()->count(3)->create(['asset_id' => $asset->id]);
        AssetTelemetrySnapshot::factory()->fuel()->count(2)->create(['asset_id' => $asset->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/assets/{$asset->id}/telemetry");
        $response->assertOk();
        $this->assertCount(
            5,
            $response->json('data'),
            'Unfiltered telemetry endpoint should return all 5 telemetry snapshots',
        );

        $response = $this->getJson("/api/{$team->slug}/assets/{$asset->id}/telemetry?type=speed");
        $response->assertOk();
        $this->assertCount(
            3,
            $response->json('data'),
            'Filtering telemetry by type=speed should return only the 3 speed snapshots',
        );
    }
}
