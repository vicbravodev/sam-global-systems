<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\RefreshAssetLivePosition;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The live position the map, the fleet list and the detail header read lives
 * on the asset row; every writer keeps it equal to the newest snapshot.
 */
class AssetLivePositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_snapshot_written_outside_the_feed_moves_the_live_position_forward_only(): void
    {
        // El reloj congelado: la aserción vuelve a calcular now() y, si entre
        // medias cambiaba el segundo, el test fallaba al azar.
        $this->freezeTime();
        $asset = Asset::factory()->create();

        AssetLocationSnapshot::factory()->for($asset)->create(['latitude' => 19.1, 'speed' => 40, 'recorded_at' => now()->subMinute()]);
        AssetLocationSnapshot::factory()->for($asset)->create(['latitude' => 18.0, 'recorded_at' => now()->subHour()]);

        $asset->refresh();
        $this->assertEqualsWithDelta(19.1, $asset->last_latitude, 0.0001);
        $this->assertEqualsWithDelta(40.0, $asset->last_speed_kph, 0.01);
        $this->assertTrue($asset->last_location_at->equalTo(now()->subMinute()->startOfSecond()));
    }

    public function test_bulk_inserted_snapshots_are_picked_up_on_request(): void
    {
        $asset = Asset::factory()->create();

        DB::table('asset_location_snapshots')->insert([
            ['asset_id' => $asset->id, 'latitude' => 20.5, 'longitude' => -100.1, 'recorded_at' => now()->subMinutes(5), 'source' => 'provider'],
            ['asset_id' => $asset->id, 'latitude' => 20.6, 'longitude' => -100.2, 'recorded_at' => now()->subMinutes(2), 'source' => 'provider'],
        ]);

        app(RefreshAssetLivePosition::class)->forAssets([$asset->id]);

        $this->assertEqualsWithDelta(20.6, $asset->fresh()->last_latitude, 0.0001);
    }

    public function test_the_map_reads_one_row_per_unit_from_the_live_columns(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Asset::factory()->create([
            'team_id' => $team->id,
            'last_latitude' => 19.43,
            'last_longitude' => -99.13,
            'last_speed_kph' => 62.5,
            'last_heading' => 90,
            'last_location_at' => now()->subSeconds(5),
        ]);
        Asset::factory()->create(['team_id' => $team->id]); // never reported

        $this->actingAs($user)
            ->get(route('assets.map', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('assets/map')
                ->has('assets', 1)
                ->where('assets.0.speed', 62.5)
                ->where('assets.0.heading', 90)
                ->where('unpositionedCount', 1));
    }

    public function test_the_detail_trail_covers_the_window_thinned_and_ending_on_the_newest_point(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        // Two hours at one point every 5 s: 1,440 points.
        $rows = [];
        foreach (range(0, 1439) as $i) {
            $rows[] = ['asset_id' => $asset->id, 'latitude' => 19.0 + $i / 10000, 'longitude' => -99.0, 'recorded_at' => now()->subSeconds(5 * $i + 1), 'source' => 'provider'];
        }
        DB::table('asset_location_snapshots')->insert($rows);

        $this->actingAs($user)
            ->get(route('assets.show', ['current_team' => $team->slug, 'asset' => $asset->id]))
            ->assertInertia(function (Assert $page) {
                $page->component('assets/show')->where('trailWindowHours', 2);

                $trail = $page->toArray()['props']['locationTrail'];
                $this->assertLessThanOrEqual(401, count($trail));
                $this->assertGreaterThan(300, count($trail));
                // Oldest first, ending on the newest point.
                $this->assertLessThan(end($trail)['recordedAt'], $trail[0]['recordedAt']);
                $this->assertEqualsWithDelta(19.0, end($trail)['latitude'], 0.0001);
            });
    }
}
