<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * UI audit P1-12: the fleet header counted only fresh signals ("En ruta 0")
 * while rows showed a 27-minute-old 83 km/h as if live, and the detail
 * header (position snapshot) and telemetry card (speed telemetry) showed two
 * different speeds. Every surface now reads one `currentSpeed`.
 */
class AssetCurrentSpeedTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_stale_speed_is_flagged_in_rows_and_not_counted_as_moving(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 83,
            'recorded_at' => now()->subMinutes(27),
        ]);

        $this->index($user)->assertInertia(
            fn (Assert $page) => $page
                ->where('assets.0.currentSpeed.kph', 83)
                ->where('assets.0.currentSpeed.stale', true)
                ->where('assets.0.currentSpeed.source', 'location')
                ->where('summary.moving', 0)
                ->where('summary.reporting', 0),
        );
    }

    public function test_fresh_speed_telemetry_newer_than_position_drives_row_and_header(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 0,
            'recorded_at' => now()->subMinutes(10),
        ]);
        AssetTelemetrySnapshot::factory()->speed()->create([
            'asset_id' => $asset->id,
            'data_json' => ['value' => 75.6, 'unit' => 'km/h'],
            'recorded_at' => now()->subMinutes(2),
        ]);

        $this->index($user)->assertInertia(
            fn (Assert $page) => $page
                ->where('assets.0.currentSpeed.kph', 75.6)
                ->where('assets.0.currentSpeed.source', 'telemetry')
                ->where('assets.0.currentSpeed.stale', false)
                ->where('summary.moving', 1),
        );
    }

    public function test_detail_header_and_telemetry_card_show_the_same_speed(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        // Speeding peak recorded as telemetry an hour ago; the position that
        // came after it says the unit is stopped.
        AssetTelemetrySnapshot::factory()->speed()->create([
            'asset_id' => $asset->id,
            'data_json' => ['value' => 75.6, 'unit' => 'km/h'],
            'recorded_at' => now()->subHour(),
        ]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 0,
            'recorded_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($user)
            ->get(route('assets.show', ['current_team' => $team->slug, 'asset' => $asset->id]))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('assets/show')
                    ->where('asset.currentSpeed.kph', 0)
                    ->where('asset.currentSpeed.source', 'location')
                    ->where('telemetry.0.type', 'speed')
                    ->where('telemetry.0.data.value', 0)
                    ->where('telemetry.0.stale', false),
            );
    }

    public function test_moving_count_never_includes_another_tenants_units(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $foreign = Asset::factory()->create(['team_id' => $userA->currentTeam->id]);
        AssetTelemetrySnapshot::factory()->speed()->create([
            'asset_id' => $foreign->id,
            'data_json' => ['value' => 90, 'unit' => 'km/h'],
            'recorded_at' => now()->subMinute(),
        ]);

        $own = Asset::factory()->create(['team_id' => $userB->currentTeam->id]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $own->id,
            'speed' => 0,
            'recorded_at' => now()->subMinute(),
        ]);

        $response = $this->assertNoTenantLeak(
            $userB->currentTeam,
            fn () => $this->index($userB),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('assets', 1)
                ->where('assets.0.id', $own->id)
                ->where('summary.moving', 0),
        );
    }

    private function index(User $user): TestResponse
    {
        return $this->actingAs($user)
            ->get(route('assets.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk();
    }
}
