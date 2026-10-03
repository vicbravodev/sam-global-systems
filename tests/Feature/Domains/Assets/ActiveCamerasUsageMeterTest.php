<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Dashcams arrive from the provider as devices attached to a vehicle, not as
 * camera-type assets, so counting only camera assets kept `active_cameras`
 * at 0 for a tenant with 228 cameras.
 */
class ActiveCamerasUsageMeterTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private UsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();

        UsageMeter::factory()->create(['code' => 'monitored_assets', 'aggregation_type' => AggregationType::Max]);
        $this->meter = UsageMeter::factory()->create(['code' => 'active_cameras', 'aggregation_type' => AggregationType::Max]);
    }

    public function test_it_counts_camera_devices_attached_to_vehicles(): void
    {
        $team = Team::factory()->create();
        $vehicleType = AssetType::factory()->vehicle()->create();

        $vehicles = Asset::factory()->active()->count(3)->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
        ]);

        // Counted: two attached cameras (Samsara `camera` and a `dashcam`).
        AssetDevice::factory()->create(['asset_id' => $vehicles[0]->id, 'device_type' => 'camera']);
        AssetDevice::factory()->create(['asset_id' => $vehicles[1]->id, 'device_type' => 'dashcam']);

        // Not counted: a gateway, a detached camera, an inactive camera device.
        AssetDevice::factory()->create(['asset_id' => $vehicles[0]->id, 'device_type' => 'gateway']);
        AssetDevice::factory()->detached()->create(['asset_id' => $vehicles[2]->id, 'device_type' => 'camera']);
        AssetDevice::factory()->inactive()->create(['asset_id' => $vehicles[2]->id, 'device_type' => 'camera']);

        // Not counted: camera on an inactive vehicle.
        $retired = Asset::factory()->inactive()->create(['team_id' => $team->id, 'asset_type_id' => $vehicleType->id]);
        AssetDevice::factory()->create(['asset_id' => $retired->id, 'device_type' => 'camera']);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $this->assertSame(2, $this->recordedCameras($team));
    }

    public function test_stand_alone_camera_assets_still_count_once(): void
    {
        $team = Team::factory()->create();
        $cameraType = AssetType::factory()->camera()->create();

        Asset::factory()->active()->create(['team_id' => $team->id, 'asset_type_id' => $cameraType->id]);

        // A camera asset that also carries its own camera device is one camera.
        $withDevice = Asset::factory()->active()->create(['team_id' => $team->id, 'asset_type_id' => $cameraType->id]);
        AssetDevice::factory()->create(['asset_id' => $withDevice->id, 'device_type' => 'camera']);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $this->assertSame(2, $this->recordedCameras($team));

        $closed = $this->assertSystemLogged('billing.daily_close.tenant_closed', fn (array $c) => $c['input']['team_id'] === $team->id);
        $this->assertSame(1, $closed['calc']['attached_cameras_count']);
        $this->assertSame(1, $closed['calc']['standalone_cameras_count']);
        $this->assertSame($this->recordedCameras($team), $closed['calc']['attached_cameras_count'] + $closed['calc']['standalone_cameras_count']);
        $this->assertTrue($closed['result']['active_cameras_recorded']);
        $this->assertSystemLogged('billing.daily_close.completed', fn (array $c) => $c['result']['cameras_count'] === 2);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_stand_alone_cameras_of_another_tenant_are_never_counted(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $cameraType = AssetType::factory()->camera()->create();

        Asset::factory()->active()->create(['team_id' => $teamA->id, 'asset_type_id' => $cameraType->id]);
        Asset::factory()->active()->count(2)->create(['team_id' => $teamB->id, 'asset_type_id' => $cameraType->id]);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $this->assertSame(1, $this->recordedCameras($teamA));
        $this->assertSame(2, $this->recordedCameras($teamB));
    }

    public function test_cameras_of_another_tenant_are_never_counted(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $vehicleType = AssetType::factory()->vehicle()->create();

        $vehicleA = Asset::factory()->active()->create(['team_id' => $teamA->id, 'asset_type_id' => $vehicleType->id]);
        AssetDevice::factory()->create(['asset_id' => $vehicleA->id, 'device_type' => 'camera']);

        foreach (Asset::factory()->active()->count(4)->create(['team_id' => $teamB->id, 'asset_type_id' => $vehicleType->id]) as $vehicleB) {
            AssetDevice::factory()->create(['asset_id' => $vehicleB->id, 'device_type' => 'camera']);
        }

        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $this->assertSame(1, $this->recordedCameras($teamA));
        $this->assertSame(4, $this->recordedCameras($teamB));
    }

    private function recordedCameras(Team $team): ?int
    {
        return UsageEvent::query()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $this->meter->id)
            ->value('quantity');
    }
}
