<?php

namespace App\Domains\Assets\Listeners;

use App\Domains\Assets\Actions\RecordAssetTelemetry;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Jobs\PollAssetTelemetryJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Normalization\Events\EventNormalized;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Harvest whatever telemetry a normalized event happens to carry.
 *
 * Provider events are a thin telemetry source — a Samsara safety event exposes
 * no instantaneous speed, fuel, odometer or engine state; the only real
 * measurement on the payload is the speeding pair, and only on speeding events.
 * The fleet-wide readings come from the stats poll ({@see PollAssetTelemetryJob}),
 * so this listener complements it rather than replacing it: it records the speed
 * measured at the moment of the event, attributed to the event that reported it.
 */
class RecordTelemetryOnEventNormalized
{
    public function __construct(
        private RecordAssetTelemetry $recordTelemetry,
    ) {}

    public function handle(EventNormalized $event): void
    {
        $normalizedEvent = $event->normalizedEvent;

        if ($normalizedEvent->asset_id === null) {
            return;
        }

        $speed = $this->resolveSpeed($normalizedEvent->payload_normalized_json ?? []);

        if ($speed === null) {
            return;
        }

        $asset = Asset::withoutGlobalScopes()
            ->whereKey($normalizedEvent->asset_id)
            ->first();

        if ($asset === null) {
            return;
        }

        $this->recordTelemetry->execute(
            asset: $asset,
            type: TelemetryType::Speed,
            value: $speed,
            unit: 'km/h',
            recordedAt: $normalizedEvent->occurred_at !== null
                ? Carbon::instance($normalizedEvent->occurred_at)
                : null,
            sourceEventId: (string) $normalizedEvent->id,
        );
    }

    /**
     * Samsara reports the speeding peak in km/h under `speedingMetadata`, which
     * the normalizer keeps as `speed_metadata`. Absent on every other event type.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveSpeed(array $payload): ?float
    {
        $speed = Arr::get($payload, 'speed_metadata.maxSpeedKilometersPerHour');

        return is_numeric($speed) ? (float) $speed : null;
    }
}
