<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Carbon\CarbonInterface;

/**
 * Persist one telemetry reading, but only when it actually says something new.
 *
 * Most diagnostics hold steady between polls — a parked truck reports the same
 * fuel level and odometer for hours. Writing an identical row every cycle would
 * grow the table by 241 assets x 5 stats per poll while adding no information,
 * so a reading is stored only when its value differs from the latest one on
 * record for that asset and type.
 */
class RecordAssetTelemetry
{
    /**
     * Returns the stored snapshot, or null when the reading was discarded as
     * unchanged or stale.
     */
    public function execute(
        Asset $asset,
        TelemetryType $type,
        float|string $value,
        ?string $unit = null,
        ?CarbonInterface $recordedAt = null,
        ?string $sourceEventId = null,
    ): ?AssetTelemetrySnapshot {
        $recordedAt ??= now();

        $latest = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('telemetry_type', $type)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        if (! $this->supersedes($latest, $value, $recordedAt)) {
            return null;
        }

        return AssetTelemetrySnapshot::create([
            'asset_id' => $asset->id,
            'telemetry_type' => $type,
            'data_json' => ['value' => $value, 'unit' => $unit],
            'recorded_at' => $recordedAt,
            // Only set for readings harvested from an event, so the row can be
            // traced back to what reported it; the stats poll leaves it null.
            'source_event_id' => $sourceEventId,
        ]);
    }

    /**
     * Whether a reading says something new over `$latest`, the newest stored
     * snapshot of the same asset and type.
     */
    public function supersedes(?AssetTelemetrySnapshot $latest, float|string $value, CarbonInterface $recordedAt): bool
    {
        return self::isNewReading(
            $latest?->data_json['value'] ?? null,
            $latest?->recorded_at,
            $value,
            $recordedAt,
        );
    }

    /**
     * The rule behind {@see supersedes()} over plain values, so a batch writer
     * can apply it to a run of readings without a model per step: a reading
     * counts only if it is newer than the previous one and its value differs.
     */
    public static function isNewReading(mixed $previousValue, ?CarbonInterface $previousAt, float|string $value, CarbonInterface $recordedAt): bool
    {
        if ($previousAt === null) {
            return true;
        }

        // Batches can come back out of order; an older reading must never
        // land on top of the current state.
        if ($recordedAt->lessThanOrEqualTo($previousAt)) {
            return false;
        }

        if ($previousValue === null) {
            return true;
        }

        if (is_string($value) || is_string($previousValue)) {
            return (string) $previousValue !== (string) $value;
        }

        // Readings are rounded at the provider boundary, so an exact compare on
        // the stored precision is enough; the epsilon only guards float noise.
        return abs((float) $previousValue - $value) >= 0.0001;
    }
}
