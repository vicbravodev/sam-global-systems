<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PurgeOldAssetTelemetryJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeOldAssetTelemetryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_readings_past_the_retention_window_and_keeps_the_rest(): void
    {
        $asset = Asset::factory()->create();

        $stale = AssetTelemetrySnapshot::factory()->for($asset)->create([
            'recorded_at' => now()->subDays(PurgeOldAssetTelemetryJob::RETENTION_DAYS + 1),
        ]);
        $fresh = AssetTelemetrySnapshot::factory()->for($asset)->create([
            'recorded_at' => now()->subDay(),
        ]);
        $edge = AssetTelemetrySnapshot::factory()->for($asset)->create([
            'recorded_at' => now()->subDays(PurgeOldAssetTelemetryJob::RETENTION_DAYS)->addMinute(),
        ]);

        $deleted = (new PurgeOldAssetTelemetryJob)->handle();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('asset_telemetry_snapshots', ['id' => $stale->id]);
        $this->assertDatabaseHas('asset_telemetry_snapshots', ['id' => $fresh->id]);
        $this->assertDatabaseHas('asset_telemetry_snapshots', ['id' => $edge->id]);
    }

    public function test_it_accepts_an_explicit_retention_window(): void
    {
        $asset = Asset::factory()->create();

        AssetTelemetrySnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(10)]);
        AssetTelemetrySnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(2)]);

        $deleted = (new PurgeOldAssetTelemetryJob(retentionDays: 7))->handle();

        $this->assertSame(1, $deleted);
        $this->assertSame(1, AssetTelemetrySnapshot::count());
    }

    public function test_it_is_a_no_op_when_nothing_is_stale(): void
    {
        $asset = Asset::factory()->create();
        AssetTelemetrySnapshot::factory()->for($asset)->create(['recorded_at' => now()]);

        $this->assertSame(0, (new PurgeOldAssetTelemetryJob)->handle());
        $this->assertSame(1, AssetTelemetrySnapshot::count());
    }
}
