<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the one-off backfill that converts historical `speed` readings from
 * miles per hour to km/h. RefreshDatabase has already run the migration
 * against an empty table, so the test seeds rows and re-runs `up()` directly.
 */
class LocationSpeedKphBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_12_204016_convert_location_snapshot_speed_to_kph.php');
    }

    public function test_it_converts_stored_speeds_from_mph_to_kph(): void
    {
        $asset = Asset::factory()->create();

        $moving = AssetLocationSnapshot::factory()->for($asset)->create(['speed' => 48.30]);
        $parked = AssetLocationSnapshot::factory()->for($asset)->create(['speed' => 0]);
        $unknown = AssetLocationSnapshot::factory()->for($asset)->create(['speed' => null]);

        $this->migration()->up();

        $this->assertEqualsWithDelta(77.73, (float) $moving->fresh()->speed, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $parked->fresh()->speed, 0.01);
        $this->assertNull($unknown->fresh()->speed);
    }

    public function test_down_restores_the_original_mph_values(): void
    {
        $asset = Asset::factory()->create();
        $snapshot = AssetLocationSnapshot::factory()->for($asset)->create(['speed' => 48.30]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertEqualsWithDelta(48.30, (float) $snapshot->fresh()->speed, 0.01);
    }

    public function test_it_leaves_no_rows_behind_when_the_table_is_empty(): void
    {
        $this->migration()->up();

        $this->assertSame(0, DB::table('asset_location_snapshots')->count());
    }
}
