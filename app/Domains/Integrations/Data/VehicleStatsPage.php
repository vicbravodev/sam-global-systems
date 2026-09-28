<?php

namespace App\Domains\Integrations\Data;

/**
 * One page of a provider's vehicle stats (feed or history), already mapped to
 * the domain's units.
 *
 * `locations` are GPS points: external_id, latitude, longitude, speed (km/h),
 * heading, formatted_location, recorded_at. `readings` are diagnostics:
 * external_id, type (TelemetryType), value, unit, recorded_at. Both may hold
 * several points per vehicle — a feed returns every update since the cursor.
 */
final readonly class VehicleStatsPage
{
    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  list<array<string, mixed>>  $readings
     */
    public function __construct(
        public array $locations,
        public array $readings,
        public ?string $endCursor,
        public bool $hasNextPage,
    ) {}

    public static function empty(?string $cursor = null): self
    {
        return new self([], [], $cursor, false);
    }
}
