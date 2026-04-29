<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentTypeCode;
use App\Domains\Incidents\Models\IncidentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentType>
 */
class IncidentTypeFactory extends Factory
{
    protected $model = IncidentType::class;

    public function definition(): array
    {
        return [
            'code' => 'type_'.Str::random(8),
            'name' => fake()->words(2, true),
            'description' => null,
            'default_priority_id' => null,
            'is_active' => true,
        ];
    }

    public function panic(): static
    {
        return $this->state(fn () => [
            'code' => IncidentTypeCode::PanicEmergency->value,
            'name' => 'Panic Emergency',
        ]);
    }

    public function geofenceBreach(): static
    {
        return $this->state(fn () => [
            'code' => IncidentTypeCode::GeofenceBreach->value,
            'name' => 'Geofence Breach',
        ]);
    }
}
