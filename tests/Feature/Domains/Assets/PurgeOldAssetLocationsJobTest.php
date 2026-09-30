<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PurgeOldAssetLocationsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PurgeOldAssetLocationsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_removes_points_past_retention_and_keeps_the_rest(): void
    {
        $this->freezeTime();
        config(['telematics.retention.location_days' => 30]);

        $asset = Asset::factory()->create();
        AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(31)]);
        AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(45)]);
        $kept = AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => now()->subDays(29)]);

        $deleted = (new PurgeOldAssetLocationsJob)->handle();

        $this->assertSame(2, $deleted);
        $this->assertSame([$kept->id], AssetLocationSnapshot::query()->pluck('id')->all());

        $purge = $this->systemLogEntries('assets.purge.completed');
        $this->assertCount(1, $purge);
        $context = $purge[0]['context'];
        $this->assertSame('asset_location_snapshots', $context['input']['table']);
        $this->assertSame(30, $context['calc']['retention_days']);
        $this->assertSame('config', $context['calc']['retention_source']);
        $this->assertSame(5000, $context['calc']['chunk_size']);
        // Recomputed from the logged retention.
        $this->assertSame(
            now()->subDays($context['calc']['retention_days'])->toIso8601String(),
            $context['calc']['cutoff'],
        );
        $this->assertSame($deleted, $context['result']['removed_count']);
        $this->assertSame(1, $context['result']['batches_count']);
        // Platform sweep: counts only, never a tenant or asset id.
        $this->assertStringNotContainsString('team_id', json_encode($context));
        $this->assertStringNotContainsString('asset_id', json_encode($context));
        $this->assertNoSensitiveDataLogged();
    }
}
