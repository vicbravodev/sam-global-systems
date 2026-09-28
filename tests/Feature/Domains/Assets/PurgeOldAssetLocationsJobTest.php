<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PurgeOldAssetLocationsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeOldAssetLocationsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_points_past_retention_and_keeps_the_rest(): void
    {
        config(['telematics.retention.location_days' => 30]);

        $asset = Asset::factory()->create();
        AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(31)]);
        AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(45)]);
        $kept = AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(29)]);

        $deleted = (new PurgeOldAssetLocationsJob)->handle();

        $this->assertSame(2, $deleted);
        $this->assertSame([$kept->id], AssetLocationSnapshot::query()->pluck('id')->all());
    }
}
