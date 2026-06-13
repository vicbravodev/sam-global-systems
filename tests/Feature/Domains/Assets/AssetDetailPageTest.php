<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssetDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['team_id' => $user->currentTeam->id]);

        $response = $this->get(route('assets.show', [
            'current_team' => $user->currentTeam->slug,
            'asset' => $asset->id,
        ]));

        $response->assertRedirect(route('login'));
    }

    public function test_page_renders_asset_detail_with_full_shape(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $asset = Asset::factory()->alert()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'provider_id' => $provider->id,
            'name' => 'Tractocamión Norte',
            'code' => 'TR-042',
            'external_primary_id' => 'samsara-vehicle-9',
            'first_seen_at' => now()->subDays(30),
        ]);
        AssetDevice::factory()->create([
            'asset_id' => $asset->id,
            'device_type' => 'dashcam',
        ]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'formatted_location' => 'Monterrey, NL',
            'recorded_at' => now()->subMinutes(3),
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('assets/show')
                ->has(
                    'asset',
                    fn (Assert $detail) => $detail
                        ->where('id', $asset->id)
                        ->where('name', 'Tractocamión Norte')
                        ->where('code', 'TR-042')
                        ->where('status', 'alert')
                        ->where('type.code', 'vehicle')
                        ->has('devices', 1)
                        ->where('devices.0.deviceType', 'dashcam')
                        ->where('lastLocation.formattedLocation', 'Monterrey, NL')
                        ->where('externalPrimaryId', 'samsara-vehicle-9')
                        ->where('provider', 'Samsara')
                        ->where('sourceIntegration', null)
                        ->where('firstSeenAt', $asset->first_seen_at->toIso8601String())
                        ->where('lastSeenAt', $asset->last_seen_at->toIso8601String())
                        ->where('driver', null)
                        ->has('lastSignalAt'),
                )
                ->has('telemetry')
                ->has('locationHistory', 1)
                ->has('incidents', 0),
        );
    }

    public function test_telemetry_returns_latest_snapshot_per_type(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);

        AssetTelemetrySnapshot::factory()->speed()->create([
            'asset_id' => $asset->id,
            'data_json' => ['value' => 40, 'unit' => 'km/h'],
            'recorded_at' => now()->subHour(),
        ]);
        AssetTelemetrySnapshot::factory()->speed()->create([
            'asset_id' => $asset->id,
            'data_json' => ['value' => 92.5, 'unit' => 'km/h'],
            'recorded_at' => now()->subMinutes(2),
        ]);
        AssetTelemetrySnapshot::factory()->fuel()->create([
            'asset_id' => $asset->id,
            'data_json' => ['value' => 61, 'unit' => 'percent'],
            'recorded_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('telemetry', 2)
                ->has(
                    'telemetry.0',
                    fn (Assert $entry) => $entry
                        ->where('type', 'speed')
                        ->where('label', 'Velocidad')
                        ->where('data.value', 92.5)
                        ->has('recordedAt'),
                )
                ->where('telemetry.1.type', 'fuel'),
        );
    }

    public function test_location_history_is_ordered_and_limited(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);

        foreach (range(1, 25) as $minutesAgo) {
            AssetLocationSnapshot::factory()->create([
                'asset_id' => $asset->id,
                'recorded_at' => now()->subMinutes($minutesAgo),
            ]);
        }
        $latest = AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'formatted_location' => 'La más reciente',
            'recorded_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('locationHistory', 20)
                ->where('locationHistory.0.id', $latest->id)
                ->where('locationHistory.0.formattedLocation', 'La más reciente'),
        );
    }

    public function test_incidents_only_include_those_linked_to_the_asset(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $otherAsset = Asset::factory()->create(['team_id' => $team->id]);

        $linked = Incident::factory()->open()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'title' => 'Frenado brusco detectado',
        ]);
        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'asset_id' => null,
        ]);
        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'asset_id' => $otherAsset->id,
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('incidents', 1)
                ->has(
                    'incidents.0',
                    fn (Assert $row) => $row
                        // The id is what powers the deep-link to
                        // /{team}/incidents/{id} from the detail (C-06).
                        ->where('id', $linked->id)
                        ->where('title', 'Frenado brusco detectado')
                        ->where('status.code', 'open')
                        ->where('priority.code', 'medium')
                        ->has('type')
                        ->has('openedAt'),
                ),
        );
    }

    public function test_detail_exposes_currently_assigned_primary_driver(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'full_name' => 'Rosa Martínez',
            'employee_code' => 'EMP-77',
        ]);
        DriverAssignment::factory()->primary()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'ended_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertInertia(
            fn (Assert $page) => $page->has(
                'asset.driver',
                fn (Assert $d) => $d
                    ->where('id', $driver->id)
                    ->where('name', 'Rosa Martínez')
                    ->where('employeeCode', 'EMP-77'),
            ),
        );
    }

    public function test_detail_driver_is_null_without_an_active_primary_assignment(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $driver = Driver::factory()->create(['team_id' => $team->id]);

        // An ended primary assignment and an active SECONDARY one must both be
        // ignored: only an active primary driver surfaces on the detail.
        DriverAssignment::factory()->primary()->ended()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
        ]);
        DriverAssignment::factory()->secondary()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'ended_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertInertia(
            fn (Assert $page) => $page->where('asset.driver', null),
        );
    }

    public function test_cross_tenant_asset_returns_404(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $foreignAsset = Asset::factory()->create([
            'team_id' => $other->currentTeam->id,
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $user->currentTeam->slug,
            'asset' => $foreignAsset->id,
        ]));

        $response->assertNotFound();
    }

    public function test_soft_deleted_asset_returns_404(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $asset->delete();

        $response = $this->actingAs($user)->get(route('assets.show', [
            'current_team' => $team->slug,
            'asset' => $asset->id,
        ]));

        $response->assertNotFound();
    }
}
