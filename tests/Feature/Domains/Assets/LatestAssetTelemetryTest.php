<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Queries\LatestAssetTelemetry;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatestAssetTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_same_rows_as_the_one_of_many_relations(): void
    {
        $team = Team::factory()->create();
        [$a, $b, $silent] = Asset::factory()->count(3)->create(['team_id' => $team->id])->all();
        $base = now()->startOfSecond()->subHour();

        foreach ([$a, $b] as $asset) {
            foreach ([TelemetryType::Speed, TelemetryType::Fuel, TelemetryType::Battery] as $offset => $type) {
                foreach ([50, 30, 10] as $minutesAgo) {
                    AssetTelemetrySnapshot::factory()->create([
                        'asset_id' => $asset->id,
                        'telemetry_type' => $type,
                        'recorded_at' => $base->copy()->subMinutes($minutesAgo - $offset),
                    ]);
                }
            }
        }

        // Two types at the very same instant: the higher id wins, as in ofMany.
        AssetTelemetrySnapshot::factory()->create(['asset_id' => $b->id, 'telemetry_type' => TelemetryType::Odometer, 'recorded_at' => $base]);
        AssetTelemetrySnapshot::factory()->create(['asset_id' => $b->id, 'telemetry_type' => TelemetryType::Ignition, 'recorded_at' => $base]);

        $expected = Asset::query()->whereKey([$a->id, $b->id, $silent->id])
            ->with(['latestTelemetry', 'latestSpeedTelemetry'])->get()->keyBy('id');

        $actual = Asset::query()->whereKey([$a->id, $b->id, $silent->id])->get();
        app(LatestAssetTelemetry::class)->loadInto($actual);

        foreach ($actual as $asset) {
            $this->assertSame($expected[$asset->id]->latestTelemetry?->id, $asset->latestTelemetry?->id, "latestTelemetry of asset {$asset->id}");
            $this->assertSame($expected[$asset->id]->latestSpeedTelemetry?->id, $asset->latestSpeedTelemetry?->id, "latestSpeedTelemetry of asset {$asset->id}");
        }

        $this->assertNull($actual->firstWhere('id', $silent->id)->latestTelemetry);
        $this->assertCount(8, app(LatestAssetTelemetry::class)->byType([$a->id, $b->id]));
    }
}
