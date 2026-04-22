<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\AttachDeviceToAsset;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachDeviceTest extends TestCase
{
    use RefreshDatabase;

    private ?AssetType $sharedAssetType = null;

    private function createAsset(): Asset
    {
        $user = User::factory()->create();

        if (! $this->sharedAssetType) {
            $this->sharedAssetType = AssetType::factory()->vehicle()->create();
        }

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'asset_type_id' => $this->sharedAssetType->id,
            'name' => 'Device Test Vehicle',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_it_attaches_device_to_asset(): void
    {
        $asset = $this->createAsset();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $action = app(AttachDeviceToAsset::class);
        $device = $action->execute(
            asset: $asset,
            deviceType: 'gps_tracker',
            providerId: $provider->id,
            externalDeviceId: 'dev-gps-001',
        );

        $this->assertNotNull(
            $device->id,
            'Device should be created and persisted with a valid ID',
        );

        $this->assertEquals(
            $asset->id,
            $device->asset_id,
            'Device should be attached to the correct asset',
        );

        $this->assertEquals(
            DeviceStatus::Active,
            $device->status,
            'Newly attached device should have Active status',
        );

        $this->assertNotNull(
            $device->attached_at,
            'Device should have an attached_at timestamp',
        );
    }

    public function test_it_detaches_device_from_previous_asset_on_reattach(): void
    {
        $firstAsset = $this->createAsset();
        $secondAsset = $this->createAsset();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $action = app(AttachDeviceToAsset::class);

        $firstDevice = $action->execute(
            asset: $firstAsset,
            deviceType: 'dashcam',
            providerId: $provider->id,
            externalDeviceId: 'dev-cam-001',
        );

        $secondDevice = $action->execute(
            asset: $secondAsset,
            deviceType: 'dashcam',
            providerId: $provider->id,
            externalDeviceId: 'dev-cam-001',
        );

        $firstDevice->refresh();

        $this->assertEquals(
            DeviceStatus::Detached,
            $firstDevice->status,
            'Previous device attachment should be marked as Detached when reassigned',
        );

        $this->assertNotNull(
            $firstDevice->detached_at,
            'Previous device attachment should have a detached_at timestamp',
        );

        $this->assertEquals(
            $secondAsset->id,
            $secondDevice->asset_id,
            'New device attachment should belong to the second asset',
        );

        $this->assertEquals(
            DeviceStatus::Active,
            $secondDevice->status,
            'New device attachment should have Active status',
        );
    }
}
