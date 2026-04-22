<?php

namespace Database\Factories\Domains\Integrations;

use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use App\Domains\Integrations\Models\IntegrationProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationProvider>
 */
class IntegrationProviderFactory extends Factory
{
    protected $model = IntegrationProvider::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'type' => fake()->randomElement(IntegrationProviderType::cases()),
            'status' => IntegrationProviderStatus::Active,
            'config_schema_json' => null,
            'capabilities_json' => null,
        ];
    }

    public function telematics(): static
    {
        return $this->state(fn () => [
            'type' => IntegrationProviderType::Telematics,
        ]);
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'type' => IntegrationProviderType::Video,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => IntegrationProviderStatus::Active,
        ]);
    }

    public function deprecated(): static
    {
        return $this->state(fn () => [
            'status' => IntegrationProviderStatus::Deprecated,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => IntegrationProviderStatus::Inactive,
        ]);
    }

    public function samsara(): static
    {
        return $this->telematics()->state(fn () => [
            'code' => 'samsara',
            'name' => 'Samsara',
            'capabilities_json' => ['gps', 'diagnostics', 'driver_behavior'],
        ]);
    }

    public function motive(): static
    {
        return $this->telematics()->state(fn () => [
            'code' => 'motive',
            'name' => 'Motive',
            'capabilities_json' => ['gps', 'eld', 'dashcam'],
        ]);
    }
}
