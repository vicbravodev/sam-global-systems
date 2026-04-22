<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetUsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsageMeters(): void
    {
        UsageMeter::create([
            'code' => 'monitored_assets',
            'name' => 'Monitored Assets',
            'unit' => 'count',
            'aggregation_type' => 'sum',
            'reset_period' => 'monthly',
        ]);

        UsageMeter::create([
            'code' => 'active_cameras',
            'name' => 'Active Cameras',
            'unit' => 'count',
            'aggregation_type' => 'sum',
            'reset_period' => 'monthly',
        ]);
    }

    public function test_it_records_monitored_assets_meter_daily(): void
    {
        $this->seedUsageMeters();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $vehicleType = AssetType::factory()->vehicle()->create();

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Active Vehicle 1',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Active Vehicle 2',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->artisan('assets:record-usage-meters')
            ->assertSuccessful();

        $meter = UsageMeter::where('code', 'monitored_assets')->first();

        $usageEvent = UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->first();

        $this->assertNotNull(
            $usageEvent,
            'A usage event should be recorded for monitored_assets meter',
        );

        $this->assertEquals(
            2,
            $usageEvent->quantity,
            'Monitored assets usage should count all non-inactive assets for the team',
        );
    }

    public function test_it_records_active_cameras_meter_daily(): void
    {
        $this->seedUsageMeters();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $cameraType = AssetType::factory()->camera()->create();
        $vehicleType = AssetType::factory()->vehicle()->create();

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $cameraType->id,
            'name' => 'Active Camera 1',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $cameraType->id,
            'name' => 'Active Camera 2',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Active Vehicle',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->artisan('assets:record-usage-meters')
            ->assertSuccessful();

        $meter = UsageMeter::where('code', 'active_cameras')->first();

        $usageEvent = UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->first();

        $this->assertNotNull(
            $usageEvent,
            'A usage event should be recorded for active_cameras meter',
        );

        $this->assertEquals(
            2,
            $usageEvent->quantity,
            'Active cameras usage should only count assets with camera category, not all assets',
        );
    }

    public function test_it_excludes_inactive_assets_from_meter_count(): void
    {
        $this->seedUsageMeters();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $vehicleType = AssetType::factory()->vehicle()->create();

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Active Vehicle',
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Inactive Vehicle',
            'status' => AssetStatus::Inactive,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicleType->id,
            'name' => 'Offline Vehicle',
            'status' => AssetStatus::Offline,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->artisan('assets:record-usage-meters')
            ->assertSuccessful();

        $meter = UsageMeter::where('code', 'monitored_assets')->first();

        $usageEvent = UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->first();

        $this->assertNotNull(
            $usageEvent,
            'A usage event should be recorded for monitored_assets meter',
        );

        $this->assertEquals(
            2,
            $usageEvent->quantity,
            'Usage meter should count Active (1) + Offline (1) = 2, excluding Inactive assets',
        );
    }
}
