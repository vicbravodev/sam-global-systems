<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\RecordAssetTelemetry;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecordAssetTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function record(Asset $asset, float|string $value, string $at, TelemetryType $type = TelemetryType::Fuel): ?AssetTelemetrySnapshot
    {
        return app(RecordAssetTelemetry::class)->execute(
            asset: $asset,
            type: $type,
            value: $value,
            unit: $type === TelemetryType::Fuel ? '%' : null,
            recordedAt: Carbon::parse($at),
        );
    }

    public function test_it_stores_the_reading_in_the_shape_the_ui_renders(): void
    {
        $asset = Asset::factory()->create();

        $snapshot = $this->record($asset, 54.0, '2026-08-12T10:00:00Z');

        $this->assertNotNull($snapshot);
        $this->assertSame(TelemetryType::Fuel, $snapshot->telemetry_type);
        // JSON has no int/float distinction for whole numbers, so 54.0 comes
        // back as 54. The UI formats any numeric value the same way.
        $this->assertEqualsWithDelta(54.0, $snapshot->fresh()->data_json['value'], 0.001);
        $this->assertSame('%', $snapshot->fresh()->data_json['unit']);
        $this->assertTrue($snapshot->recorded_at->equalTo(Carbon::parse('2026-08-12T10:00:00Z')));
    }

    public function test_it_does_not_insert_a_row_when_the_value_has_not_changed(): void
    {
        $asset = Asset::factory()->create();

        $this->record($asset, 54.0, '2026-08-12T10:00:00Z');
        $second = $this->record($asset, 54.0, '2026-08-12T10:15:00Z');

        // Most stats hold steady between polls; storing an identical row every
        // 15 minutes would grow the table without adding information.
        $this->assertNull($second);
        $this->assertSame(1, AssetTelemetrySnapshot::where('asset_id', $asset->id)->count());
    }

    public function test_it_inserts_a_new_row_when_the_value_changes(): void
    {
        $asset = Asset::factory()->create();

        $this->record($asset, 54.0, '2026-08-12T10:00:00Z');
        $this->record($asset, 53.0, '2026-08-12T10:15:00Z');

        $this->assertSame(2, AssetTelemetrySnapshot::where('asset_id', $asset->id)->count());
    }

    public function test_it_tracks_each_telemetry_type_independently(): void
    {
        $asset = Asset::factory()->create();

        $this->record($asset, 54.0, '2026-08-12T10:00:00Z', TelemetryType::Fuel);
        $ignition = $this->record($asset, 'On', '2026-08-12T10:00:00Z', TelemetryType::Ignition);

        // An unchanged fuel level must not suppress a different stat's reading.
        $this->assertNotNull($ignition);
        $this->assertSame(2, AssetTelemetrySnapshot::where('asset_id', $asset->id)->count());
    }

    public function test_it_tracks_each_asset_independently(): void
    {
        $first = Asset::factory()->create();
        $second = Asset::factory()->create();

        $this->record($first, 54.0, '2026-08-12T10:00:00Z');
        $other = $this->record($second, 54.0, '2026-08-12T10:00:00Z');

        $this->assertNotNull($other);
        $this->assertSame(1, AssetTelemetrySnapshot::where('asset_id', $second->id)->count());
    }

    public function test_it_ignores_a_reading_older_than_the_one_already_stored(): void
    {
        $asset = Asset::factory()->create();

        $this->record($asset, 54.0, '2026-08-12T10:00:00Z');
        $stale = $this->record($asset, 99.0, '2026-08-12T09:00:00Z');

        // Batches can return out of order; an older reading must never
        // overwrite the current state.
        $this->assertNull($stale);
        $this->assertSame(1, AssetTelemetrySnapshot::where('asset_id', $asset->id)->count());
    }

    public function test_it_keeps_categorical_values_as_strings(): void
    {
        $asset = Asset::factory()->create();

        $snapshot = $this->record($asset, 'Idle', '2026-08-12T10:00:00Z', TelemetryType::Ignition);

        $this->assertSame(['value' => 'Idle', 'unit' => null], $snapshot->data_json);
    }
}
