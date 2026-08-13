<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\SyncAssetDevices;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Events\AssetDiscovered;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncAssetDevicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Team, 1: IntegrationProvider, 2: TenantIntegration}
     */
    private function createSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $provider = IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => 'active',
        ]);

        AssetType::factory()->vehicle()->create();

        return [$team, $provider, $integration];
    }

    public function test_the_integration_sync_registers_the_devices_reported_by_the_provider(): void
    {
        Event::fake([AssetDiscovered::class]);

        [$team, $provider, $integration] = $this->createSetup();

        $asset = app(SyncAssetFromIntegration::class)->execute($team->id, $integration->id, [
            'external_id' => '281474993032573',
            'name' => 'T-195',
            'asset_type_code' => 'vehicle',
            'devices' => [
                ['device_type' => 'gateway', 'external_device_id' => 'GHWJPVPM8J', 'metadata' => ['model' => 'VG34']],
                ['device_type' => 'camera', 'external_device_id' => 'CM-123'],
            ],
        ]);

        $this->assertDatabaseCount('asset_devices', 2);

        $this->assertDatabaseHas('asset_devices', [
            'asset_id' => $asset->id,
            'provider_id' => $provider->id,
            'device_type' => 'gateway',
            'external_device_id' => 'GHWJPVPM8J',
            'status' => DeviceStatus::Active->value,
            'detached_at' => null,
        ]);

        $this->assertDatabaseHas('asset_devices', [
            'asset_id' => $asset->id,
            'device_type' => 'camera',
            'external_device_id' => 'CM-123',
        ]);

        $gateway = AssetDevice::where('external_device_id', 'GHWJPVPM8J')->firstOrFail();
        $this->assertSame('VG34', $gateway->metadata_json['model'] ?? null);
    }

    public function test_resyncing_the_same_devices_is_idempotent(): void
    {
        Event::fake([AssetDiscovered::class]);

        [$team, , $integration] = $this->createSetup();

        $payload = [
            'external_id' => 'veh-1',
            'name' => 'Truck',
            'asset_type_code' => 'vehicle',
            'devices' => [
                ['device_type' => 'gateway', 'external_device_id' => 'GW-1', 'metadata' => ['model' => 'VG34']],
            ],
        ];

        $action = app(SyncAssetFromIntegration::class);
        $action->execute($team->id, $integration->id, $payload);

        // The provider now reports a newer gateway model for the same serial.
        $payload['devices'][0]['metadata'] = ['model' => 'VG55NA'];
        $action->execute($team->id, $integration->id, $payload);
        $action->execute($team->id, $integration->id, $payload);

        // Repeated syncs of the same external_device_id must update the row,
        // never pile up one duplicate per tick.
        $this->assertDatabaseCount('asset_devices', 1);

        $device = AssetDevice::firstOrFail();
        $this->assertSame(DeviceStatus::Active, $device->status);
        $this->assertNull($device->detached_at);
        $this->assertSame('VG55NA', $device->metadata_json['model'] ?? null);
    }

    public function test_a_device_the_provider_stops_listing_is_detached(): void
    {
        [$team, $provider, $integration] = $this->createSetup();
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        $sync = app(SyncAssetDevices::class);

        $sync->execute($asset, $provider->id, [
            ['device_type' => 'gateway', 'external_device_id' => 'GW-1'],
            ['device_type' => 'camera', 'external_device_id' => 'CAM-1'],
        ]);

        // Camera removed from the vehicle at the provider.
        $sync->execute($asset, $provider->id, [
            ['device_type' => 'gateway', 'external_device_id' => 'GW-1'],
        ]);

        $camera = AssetDevice::where('external_device_id', 'CAM-1')->firstOrFail();
        $this->assertSame(DeviceStatus::Detached, $camera->status);
        $this->assertNotNull($camera->detached_at);
        $this->assertFalse($camera->isAttached());

        $gateway = AssetDevice::where('external_device_id', 'GW-1')->firstOrFail();
        $this->assertTrue($gateway->isAttached(), 'A still-listed device must stay attached');
    }

    public function test_an_empty_device_list_detaches_every_device_of_that_provider(): void
    {
        [$team, $provider, $integration] = $this->createSetup();
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        $sync = app(SyncAssetDevices::class);
        $sync->execute($asset, $provider->id, [
            ['device_type' => 'gateway', 'external_device_id' => 'GW-1'],
        ]);
        $sync->execute($asset, $provider->id, []);

        $this->assertSame(
            0,
            AssetDevice::where('asset_id', $asset->id)->whereNull('detached_at')->count(),
        );
    }

    public function test_a_sync_payload_without_a_devices_key_leaves_devices_untouched(): void
    {
        Event::fake([AssetDiscovered::class]);

        [$team, $provider, $integration] = $this->createSetup();

        $action = app(SyncAssetFromIntegration::class);
        $asset = $action->execute($team->id, $integration->id, [
            'external_id' => 'veh-2',
            'name' => 'Truck',
            'asset_type_code' => 'vehicle',
            'devices' => [['device_type' => 'gateway', 'external_device_id' => 'GW-9']],
        ]);

        // A provider that does not report devices at all must never be read as
        // "this asset has no devices" — that would detach the whole fleet.
        $action->execute($team->id, $integration->id, [
            'external_id' => 'veh-2',
            'name' => 'Truck',
            'asset_type_code' => 'vehicle',
        ]);

        $this->assertTrue(
            AssetDevice::where('asset_id', $asset->id)->firstOrFail()->isAttached(),
        );
    }

    public function test_devices_without_an_external_id_are_ignored(): void
    {
        [$team, $provider] = $this->createSetup();
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        app(SyncAssetDevices::class)->execute($asset, $provider->id, [
            ['device_type' => 'gateway', 'external_device_id' => null],
            ['device_type' => 'camera', 'external_device_id' => ''],
        ]);

        // Without an external id a device cannot be matched on the next sync,
        // so it is never registered.
        $this->assertDatabaseCount('asset_devices', 0);
    }

    public function test_a_device_moved_to_another_asset_is_detached_from_the_first_one(): void
    {
        [$team, $provider] = $this->createSetup();
        $first = Asset::factory()->create(['team_id' => $team->id]);
        $second = Asset::factory()->create(['team_id' => $team->id]);

        $sync = app(SyncAssetDevices::class);
        $sync->execute($first, $provider->id, [['device_type' => 'camera', 'external_device_id' => 'CAM-7']]);
        $sync->execute($second, $provider->id, [['device_type' => 'camera', 'external_device_id' => 'CAM-7']]);

        $this->assertSame(
            1,
            AssetDevice::whereNull('detached_at')->where('asset_id', $second->id)->count(),
        );
        $this->assertSame(
            0,
            AssetDevice::whereNull('detached_at')->where('asset_id', $first->id)->count(),
        );
    }

    public function test_devices_of_another_tenant_are_never_touched(): void
    {
        [$team, $provider] = $this->createSetup();
        $otherTeam = User::factory()->create()->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $foreignAsset = Asset::factory()->create(['team_id' => $otherTeam->id]);

        $sync = app(SyncAssetDevices::class);
        $sync->execute($foreignAsset, $provider->id, [['device_type' => 'gateway', 'external_device_id' => 'GW-FOREIGN']]);
        $sync->execute($asset, $provider->id, [['device_type' => 'gateway', 'external_device_id' => 'GW-OWN']]);

        // Reconciling this tenant's asset must not detach the other tenant's device.
        $sync->execute($asset, $provider->id, []);

        $this->assertTrue(
            AssetDevice::where('external_device_id', 'GW-FOREIGN')->firstOrFail()->isAttached(),
        );
    }
}
