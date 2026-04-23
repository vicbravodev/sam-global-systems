<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Models\EventRecentHistorySnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRecentHistorySnapshot>
 */
class EventRecentHistorySnapshotFactory extends Factory
{
    protected $model = EventRecentHistorySnapshot::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'window_start' => now()->subMinutes(60),
            'window_end' => now(),
            'recent_events_count' => 0,
            'recent_incidents_count' => 0,
            'recent_same_type_count' => 0,
            'recent_high_severity_count' => 0,
            'recent_locations_json' => [],
            'recent_flags_json' => [],
        ];
    }
}
