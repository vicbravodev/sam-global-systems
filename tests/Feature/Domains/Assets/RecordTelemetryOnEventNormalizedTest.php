<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Listeners\RecordTelemetryOnEventNormalized;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecordTelemetryOnEventNormalizedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizedEvent(?Asset $asset, array $payload, ?Carbon $occurredAt = null): NormalizedEvent
    {
        return NormalizedEvent::factory()->create([
            'team_id' => $asset?->team_id ?? User::factory()->create()->currentTeam->id,
            'asset_id' => $asset?->id,
            'occurred_at' => $occurredAt ?? now(),
            'payload_normalized_json' => $payload,
        ]);
    }

    public function test_a_speeding_event_records_the_measured_speed_as_telemetry(): void
    {
        $asset = Asset::factory()->create(['last_seen_at' => now()->subDays(10)]);
        $occurredAt = Carbon::parse('2026-08-10T09:30:00Z');

        $event = $this->normalizedEvent($asset, [
            'event_type_code' => 'speeding',
            'speed_metadata' => [
                'maxSpeedKilometersPerHour' => 118,
                'postedSpeedLimitKilometersPerHour' => 80,
            ],
        ], $occurredAt);

        app(RecordTelemetryOnEventNormalized::class)->handle(new EventNormalized($event));

        $snapshot = AssetTelemetrySnapshot::where('asset_id', $asset->id)->firstOrFail();

        $this->assertSame(TelemetryType::Speed, $snapshot->telemetry_type);
        $this->assertEquals(118.0, $snapshot->data_json['value']);
        $this->assertSame('km/h', $snapshot->data_json['unit']);
        $this->assertTrue($snapshot->recorded_at->equalTo($occurredAt));
        $this->assertSame(
            (string) $event->id,
            $snapshot->source_event_id,
            'The reading must be attributable to the event that reported it',
        );
    }

    public function test_an_event_without_speed_data_records_nothing(): void
    {
        $asset = Asset::factory()->create();

        // A panic button carries no speed at all — the only numeric field on a
        // non-speeding safety event is the acceleration peak.
        $event = $this->normalizedEvent($asset, [
            'event_type_code' => 'panic_button',
            'description' => 'Botón de pánico',
        ]);

        app(RecordTelemetryOnEventNormalized::class)->handle(new EventNormalized($event));

        $this->assertDatabaseCount('asset_telemetry_snapshots', 0);
    }

    public function test_an_event_with_no_resolved_asset_records_nothing(): void
    {
        $event = $this->normalizedEvent(null, [
            'speed_metadata' => ['maxSpeedKilometersPerHour' => 118],
        ]);

        app(RecordTelemetryOnEventNormalized::class)->handle(new EventNormalized($event));

        $this->assertDatabaseCount('asset_telemetry_snapshots', 0);
    }

    public function test_replaying_the_same_event_is_idempotent(): void
    {
        $asset = Asset::factory()->create();
        $event = $this->normalizedEvent($asset, [
            'speed_metadata' => ['maxSpeedKilometersPerHour' => 118],
        ], Carbon::parse('2026-08-10T09:30:00Z'));

        $listener = app(RecordTelemetryOnEventNormalized::class);
        $listener->handle(new EventNormalized($event));
        $listener->handle(new EventNormalized($event));

        $this->assertDatabaseCount('asset_telemetry_snapshots', 1);
    }

    public function test_it_is_wired_to_the_normalization_pipeline(): void
    {
        $asset = Asset::factory()->create();
        $event = $this->normalizedEvent($asset, [
            'speed_metadata' => ['maxSpeedKilometersPerHour' => 95],
        ]);

        EventNormalized::dispatch($event);

        $this->assertDatabaseHas('asset_telemetry_snapshots', [
            'asset_id' => $asset->id,
            'telemetry_type' => TelemetryType::Speed->value,
        ]);
    }
}
