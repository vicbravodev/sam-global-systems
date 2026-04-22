<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetDevice>
 */
class AssetDeviceFactory extends Factory
{
    protected $model = AssetDevice::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'device_type' => fake()->randomElement(['gps_tracker', 'dashcam', 'eld', 'temperature_sensor']),
            'external_device_id' => fake()->uuid(),
            'status' => DeviceStatus::Active,
            'attached_at' => now(),
        ];
    }

    public function detached(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Detached,
            'detached_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Inactive,
        ]);
    }
}
