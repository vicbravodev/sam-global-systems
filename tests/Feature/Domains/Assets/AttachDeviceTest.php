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

    private function createAsset(?int $teamId = null): Asset
    {
        if (! $this->sharedAssetType) {
            $this->sharedAssetType = AssetType::factory()->vehicle()->create();
        }

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $teamId ?? User::factory()->create()->currentTeam->id,
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
        // A dashcam physically moved between two of the tenant's own vehicles.
        $firstAsset = $this->createAsset();
        $secondAsset = $this->createAsset($firstAsset->team_id);
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

    public function test_reattaching_the_same_device_to_the_same_asset_is_idempotent(): void
    {
        $asset = $this->createAsset();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $action = app(AttachDeviceToAsset::class);

        $first = $action->execute($asset, 'gateway', $provider->id, 'GW-1', ['model' => 'VG34']);
        $second = $action->execute($asset, 'gateway', $provider->id, 'GW-1', ['model' => 'VG55NA']);

        $this->assertEquals(
            $first->id,
            $second->id,
            'The provider replays its device list on every sync, so an already-attached '
                .'device must be refreshed in place instead of adding a row per tick',
        );

        $this->assertDatabaseCount('asset_devices', 1);
        $this->assertSame('VG55NA', $second->metadata_json['model']);
        $this->assertEquals(DeviceStatus::Active, $second->fresh()->status);
        $this->assertNull($second->fresh()->detached_at);
    }

    public function test_a_serial_collision_across_tenants_never_detaches_the_other_tenants_device(): void
    {
        $asset = $this->createAsset();
        $foreignAsset = $this->createAsset();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $action = app(AttachDeviceToAsset::class);

        $foreignDevice = $action->execute($foreignAsset, 'gateway', $provider->id, 'GW-SHARED');
        $action->execute($asset, 'gateway', $provider->id, 'GW-SHARED');

        $this->assertTrue(
            $foreignDevice->fresh()->isAttached(),
            'asset_devices carries no team_id, so the previous-attachment lookup must be '
                .'scoped by team — otherwise one tenant`s sync detaches another tenant`s device',
        );
    }
}
