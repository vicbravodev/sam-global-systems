<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetTelemetrySnapshot>
 */
class AssetTelemetrySnapshotFactory extends Factory
{
    protected $model = AssetTelemetrySnapshot::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'telemetry_type' => fake()->randomElement(TelemetryType::cases()),
            'data_json' => ['value' => fake()->randomFloat(2, 0, 100)],
            'recorded_at' => now(),
        ];
    }

    public function speed(): static
    {
        return $this->state(fn () => [
            'telemetry_type' => TelemetryType::Speed,
            'data_json' => ['value' => fake()->randomFloat(2, 0, 120), 'unit' => 'km/h'],
        ]);
    }

    public function fuel(): static
    {
        return $this->state(fn () => [
            'telemetry_type' => TelemetryType::Fuel,
            'data_json' => ['value' => fake()->randomFloat(2, 0, 100), 'unit' => 'percent'],
        ]);
    }

    public function temperature(): static
    {
        return $this->state(fn () => [
            'telemetry_type' => TelemetryType::Temperature,
            'data_json' => ['value' => fake()->randomFloat(2, -20, 50), 'unit' => 'celsius'],
        ]);
    }
}
