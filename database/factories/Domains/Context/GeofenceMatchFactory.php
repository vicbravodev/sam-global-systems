<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Models\Geofence;
use App\Domains\Context\Models\GeofenceMatch;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeofenceMatch>
 */
class GeofenceMatchFactory extends Factory
{
    protected $model = GeofenceMatch::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'geofence_id' => Geofence::factory(),
            'match_type' => GeofenceMatchType::Inside,
            'matched_at' => now(),
            'distance_meters' => null,
            'metadata_json' => null,
        ];
    }

    public function inside(): static
    {
        return $this->state(fn () => ['match_type' => GeofenceMatchType::Inside]);
    }

    public function nearBoundary(): static
    {
        return $this->state(fn () => [
            'match_type' => GeofenceMatchType::NearBoundary,
            'distance_meters' => 150,
        ]);
    }
}
