<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventContextSnapshot>
 */
class EventContextSnapshotFactory extends Factory
{
    protected $model = EventContextSnapshot::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'team_id' => Team::factory(),
            'asset_id' => null,
            'driver_id' => null,
            'event_occurred_at' => now(),
            'context_version' => 1,
            'location_snapshot_json' => null,
            'asset_snapshot_json' => null,
            'driver_snapshot_json' => null,
            'telemetry_snapshot_json' => null,
            'geofence_snapshot_json' => [],
            'incidents_snapshot_json' => [],
            'recent_history_snapshot_json' => null,
            'media_snapshot_json' => [],
            'signals_json' => [],
        ];
    }
}
