<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\UpdateAssetTelemetrySnapshot;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpdateAssetTelemetrySnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a_telemetry_snapshot(): void
    {
        $asset = Asset::factory()->create();
        $recordedAt = Carbon::parse('2026-08-10T09:30:00Z');

        $snapshot = app(UpdateAssetTelemetrySnapshot::class)->execute(
            asset: $asset,
            type: TelemetryType::Fuel,
            data: ['value' => 54.0, 'unit' => 'percent'],
            recordedAt: $recordedAt,
        );

        $this->assertDatabaseCount('asset_telemetry_snapshots', 1);
        $this->assertSame($asset->id, $snapshot->asset_id);
        $this->assertSame(TelemetryType::Fuel, $snapshot->telemetry_type);
        // JSON has no int/float distinction, so a whole-number reading round-trips as int.
        $this->assertEquals(54.0, $snapshot->data_json['value']);
        $this->assertSame('percent', $snapshot->data_json['unit']);
        $this->assertTrue($snapshot->recorded_at->equalTo($recordedAt));
    }

    public function test_replaying_the_same_reading_is_idempotent(): void
    {
        $asset = Asset::factory()->create();
        $recordedAt = Carbon::parse('2026-08-10T09:30:00Z');

        $action = app(UpdateAssetTelemetrySnapshot::class);

        $first = $action->execute($asset, TelemetryType::Fuel, ['value' => 54.0], $recordedAt);
        $second = $action->execute($asset, TelemetryType::Fuel, ['value' => 54.0], $recordedAt);

        $this->assertSame(
            $first->id,
            $second->id,
            'The same reading (asset + type + recorded_at) must update in place, not duplicate — '
                .'the poller re-reads the provider snapshot on every tick',
        );
        $this->assertDatabaseCount('asset_telemetry_snapshots', 1);
    }

    public function test_a_new_reading_of_the_same_type_is_a_new_snapshot(): void
    {
        $asset = Asset::factory()->create();
        $action = app(UpdateAssetTelemetrySnapshot::class);

        $action->execute($asset, TelemetryType::Fuel, ['value' => 54.0], Carbon::parse('2026-08-10T09:30:00Z'));
        $action->execute($asset, TelemetryType::Fuel, ['value' => 51.0], Carbon::parse('2026-08-10T09:35:00Z'));

        $this->assertDatabaseCount('asset_telemetry_snapshots', 2);

        $asset->refresh();
        $this->assertEquals(51.0, $asset->latestTelemetry->data_json['value']);
    }

    public function test_telemetry_is_a_real_signal_and_bumps_last_seen_at(): void
    {
        $asset = Asset::factory()->create(['last_seen_at' => now()->subDays(30)]);
        $recordedAt = now()->subMinutes(3)->startOfSecond();

        app(UpdateAssetTelemetrySnapshot::class)->execute(
            $asset,
            TelemetryType::Ignition,
            ['value' => 'on'],
            $recordedAt,
        );

        $asset->refresh();
        $this->assertTrue(
            $asset->last_seen_at->equalTo($recordedAt),
            'Telemetry is a real signal from the device, so it moves last_seen_at like a location does',
        );
    }

    public function test_a_stale_reading_never_drags_last_seen_at_backwards(): void
    {
        $seenAt = now()->subMinutes(2)->startOfSecond();
        $asset = Asset::factory()->create(['last_seen_at' => $seenAt]);

        app(UpdateAssetTelemetrySnapshot::class)->execute(
            $asset,
            TelemetryType::Odometer,
            ['value' => 14010.3, 'unit' => 'km'],
            now()->subDays(2),
        );

        $asset->refresh();
        $this->assertTrue(
            $asset->last_seen_at->equalTo($seenAt),
            'Backfilled or stale readings must not make a live asset look older than it is',
        );
    }

    public function test_it_defaults_recorded_at_to_now(): void
    {
        $asset = Asset::factory()->create();

        $snapshot = app(UpdateAssetTelemetrySnapshot::class)
            ->execute($asset, TelemetryType::Battery, ['value' => 12.6, 'unit' => 'volts']);

        $this->assertNotNull($snapshot->recorded_at);
        $this->assertTrue($snapshot->recorded_at->diffInMinutes(now()) < 1);
    }

    public function test_it_stores_the_source_event_id_when_the_reading_comes_from_an_event(): void
    {
        $asset = Asset::factory()->create();

        $snapshot = app(UpdateAssetTelemetrySnapshot::class)->execute(
            asset: $asset,
            type: TelemetryType::Speed,
            data: ['value' => 118.0, 'unit' => 'km/h'],
            recordedAt: now(),
            sourceEventId: 'normalized-event-42',
        );

        $this->assertSame('normalized-event-42', $snapshot->source_event_id);
    }

    public function test_telemetry_stays_scoped_to_its_own_tenant_asset(): void
    {
        $asset = Asset::factory()->create();
        $foreignAsset = Asset::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $action = app(UpdateAssetTelemetrySnapshot::class);
        $action->execute($asset, TelemetryType::Fuel, ['value' => 10.0]);
        $action->execute($foreignAsset, TelemetryType::Fuel, ['value' => 90.0]);

        $this->assertSame(
            1,
            AssetTelemetrySnapshot::where('asset_id', $asset->id)->count(),
        );
        $this->assertEquals(
            10.0,
            AssetTelemetrySnapshot::where('asset_id', $asset->id)->firstOrFail()->data_json['value'],
        );
    }
}
