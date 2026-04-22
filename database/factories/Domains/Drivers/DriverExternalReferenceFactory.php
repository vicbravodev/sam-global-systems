<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Integrations\Models\IntegrationProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverExternalReference>
 */
class DriverExternalReferenceFactory extends Factory
{
    protected $model = DriverExternalReference::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'provider_id' => IntegrationProvider::factory(),
            'external_id' => fake()->unique()->uuid(),
            'external_type' => 'driver',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
