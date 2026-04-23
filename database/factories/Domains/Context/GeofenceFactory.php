<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceType;
use App\Domains\Context\Models\Geofence;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Geofence>
 */
class GeofenceFactory extends Factory
{
    protected $model = Geofence::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->company().' Zone',
            'code' => strtoupper(fake()->bothify('GF-####')),
            'geofence_type' => GeofenceType::Zone,
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-99.20, 19.40],
                    [-99.10, 19.40],
                    [-99.10, 19.50],
                    [-99.20, 19.50],
                    [-99.20, 19.40],
                ]],
            ],
            'category' => GeofenceCategory::ClientSite,
            'is_active' => true,
            'metadata_json' => null,
        ];
    }

    public function zone(): static
    {
        return $this->state(fn () => ['geofence_type' => GeofenceType::Zone]);
    }

    public function point(): static
    {
        return $this->state(fn () => [
            'geofence_type' => GeofenceType::Point,
            'geometry_json' => [
                'type' => 'Point',
                'coordinates' => [-99.1332, 19.4326],
                'radius_meters' => 500,
            ],
        ]);
    }

    public function riskZone(): static
    {
        return $this->state(fn () => ['category' => GeofenceCategory::RiskZone]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
