<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssetsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->create();

        $response = $this->get(
            route('assets.index', ['current_team' => $user->currentTeam->slug]),
        );

        $response->assertRedirect(route('login'));
    }

    public function test_page_renders_assets_with_row_shape(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        $asset = Asset::factory()->alert()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Tractocamión Norte',
            'code' => 'TR-042',
            'last_seen_at' => now()->subMinutes(5),
        ]);
        AssetDevice::factory()->create([
            'asset_id' => $asset->id,
            'device_type' => 'gps_tracker',
        ]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'recorded_at' => now()->subHour(),
        ]);
        $latest = AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'latitude' => 25.6866142,
            'longitude' => -100.3161126,
            'formatted_location' => 'Monterrey, NL',
            'recorded_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('assets/index')
                ->has('assets', 1)
                ->has(
                    'assets.0',
                    fn (Assert $row) => $row
                        ->where('id', $asset->id)
                        ->where('name', 'Tractocamión Norte')
                        ->where('code', 'TR-042')
                        ->where('status', 'alert')
                        ->where('type.code', 'vehicle')
                        ->where('type.name', 'Vehicle')
                        ->where('type.category', 'vehicle')
                        ->has('devices', 1)
                        ->where('devices.0.deviceType', 'gps_tracker')
                        ->has(
                            'lastLocation',
                            fn (Assert $location) => $location
                                ->where('latitude', 25.6866142)
                                ->where('longitude', -100.3161126)
                                ->where('formattedLocation', 'Monterrey, NL')
                                ->where('recordedAt', $latest->recorded_at->toIso8601String())
                                ->has('speed')
                                ->has('heading'),
                        )
                        ->where('lastSeenAt', $asset->last_seen_at->toIso8601String())
                        ->where('lastSignalAt', $latest->recorded_at->toIso8601String()),
                )
                ->where('pagination.page', 1)
                ->where('pagination.total', 1)
                ->has('filterOptions.statuses', 6)
                ->has('filterOptions.types', 1),
        );
    }

    public function test_last_signal_reflects_each_assets_own_signal_not_the_sync_bump(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        // The three assets share the same bulk sync bump (C1-a repro): the
        // exposed signal must still differ because their real signals differ.
        $syncedAt = now()->subMinutes(2);

        $fresh = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'last_seen_at' => $syncedAt,
        ]);
        $freshLocation = AssetLocationSnapshot::factory()->create([
            'asset_id' => $fresh->id,
            'recorded_at' => now()->subMinutes(4),
        ]);

        $stale = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'last_seen_at' => $syncedAt,
        ]);
        $staleLocation = AssetLocationSnapshot::factory()->create([
            'asset_id' => $stale->id,
            'recorded_at' => now()->subDays(190),
        ]);

        $silent = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'last_seen_at' => $syncedAt,
        ]);

        $response = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(function (Assert $page) use ($fresh, $stale, $silent, $freshLocation, $staleLocation) {
            $signals = collect($page->toArray()['props']['assets'])
                ->keyBy('id')
                ->map(fn (array $row) => $row['lastSignalAt']);

            $this->assertSame($freshLocation->recorded_at->toIso8601String(), $signals[$fresh->id]);
            $this->assertSame($staleLocation->recorded_at->toIso8601String(), $signals[$stale->id]);
            $this->assertNotSame($signals[$fresh->id], $signals[$stale->id]);

            // An asset that never reported anything exposes no signal at all,
            // so the UI can never render a fake "hace N min" for it.
            $this->assertNull($signals[$silent->id]);
        });
    }

    public function test_last_signal_uses_telemetry_when_newer_than_location(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        $asset = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'recorded_at' => now()->subHours(2),
        ]);
        $telemetry = AssetTelemetrySnapshot::factory()->speed()->create([
            'asset_id' => $asset->id,
            'recorded_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page->where(
                'assets.0.lastSignalAt',
                $telemetry->recorded_at->toIso8601String(),
            ),
        );
    }

    public function test_detached_devices_are_excluded(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        $asset = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);
        AssetDevice::factory()->detached()->create(['asset_id' => $asset->id]);

        $response = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page->has('assets.0.devices', 0),
        );
    }

    public function test_filters_by_status(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        Asset::factory()->critical()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Unidad crítica',
        ]);
        Asset::factory()->active()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('assets.index', [
                'current_team' => $team->slug,
                'status' => 'critical',
            ]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 1)
                ->where('assets.0.name', 'Unidad crítica')
                ->where('filters.status', 'critical'),
        );
    }

    public function test_filters_by_type(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $vehicle = AssetType::factory()->vehicle()->create();
        $trailer = AssetType::factory()->trailer()->create();

        Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $vehicle->id,
            'name' => 'Camión',
        ]);
        Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $trailer->id,
            'name' => 'Remolque',
        ]);

        $response = $this->actingAs($user)->get(
            route('assets.index', [
                'current_team' => $team->slug,
                'type' => 'vehicle',
            ]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 1)
                ->where('assets.0.name', 'Camión')
                ->where('filters.type', 'vehicle'),
        );
    }

    public function test_search_matches_name_or_code_case_insensitive(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Tractocamión Norte',
            'code' => 'TR-042',
        ]);
        Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Caja seca Sur',
            'code' => 'CS-007',
        ]);

        $byName = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug, 'q' => 'NORTE']),
        );
        $byName->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 1)
                ->where('assets.0.code', 'TR-042'),
        );

        $byCode = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug, 'q' => 'cs-0']),
        );
        $byCode->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 1)
                ->where('assets.0.code', 'CS-007'),
        );
    }

    public function test_only_exposes_current_team_assets(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        Asset::factory()->count(2)->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);

        $other = User::factory()->create();
        Asset::factory()->create([
            'team_id' => $other->currentTeam->id,
            'asset_type_id' => $type->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 2)
                ->where('pagination.total', 2),
        );
    }

    public function test_soft_deleted_assets_are_hidden(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        $asset = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);
        $asset->delete();

        $response = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 0)
                ->where('pagination.total', 0),
        );
    }

    public function test_pagination_limits_and_reports_meta(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        Asset::factory()->count(60)->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
        ]);

        $firstPage = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug]),
        );
        $firstPage->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 50)
                ->where('pagination.page', 1)
                ->where('pagination.perPage', 50)
                ->where('pagination.total', 60)
                ->where('pagination.lastPage', 2),
        );

        $secondPage = $this->actingAs($user)->get(
            route('assets.index', ['current_team' => $team->slug, 'page' => 2]),
        );
        $secondPage->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 10)
                ->where('pagination.page', 2),
        );
    }
}
