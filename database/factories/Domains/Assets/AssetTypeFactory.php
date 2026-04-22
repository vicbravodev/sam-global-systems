<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Models\AssetType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetType>
 */
class AssetTypeFactory extends Factory
{
    protected $model = AssetType::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'category' => fake()->randomElement(AssetCategory::cases()),
            'capabilities_json' => null,
        ];
    }

    public function vehicle(): static
    {
        return $this->state(fn () => [
            'code' => 'vehicle',
            'name' => 'Vehicle',
            'category' => AssetCategory::Vehicle,
            'capabilities_json' => ['gps', 'diagnostics', 'fuel'],
        ]);
    }

    public function trailer(): static
    {
        return $this->state(fn () => [
            'code' => 'trailer',
            'name' => 'Trailer',
            'category' => AssetCategory::Trailer,
            'capabilities_json' => ['gps', 'temperature'],
        ]);
    }

    public function camera(): static
    {
        return $this->state(fn () => [
            'code' => 'camera',
            'name' => 'Camera',
            'category' => AssetCategory::Camera,
            'capabilities_json' => ['video', 'motion_detection'],
        ]);
    }

    public function gpsDevice(): static
    {
        return $this->state(fn () => [
            'code' => 'gps_device',
            'name' => 'GPS Device',
            'category' => AssetCategory::GpsDevice,
            'capabilities_json' => ['gps', 'geofencing'],
        ]);
    }

    public function sensor(): static
    {
        return $this->state(fn () => [
            'code' => 'sensor',
            'name' => 'Sensor',
            'category' => AssetCategory::Sensor,
            'capabilities_json' => ['temperature', 'humidity'],
        ]);
    }
}
