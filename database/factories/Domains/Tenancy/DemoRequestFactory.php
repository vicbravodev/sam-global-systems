<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\DemoRequestStatus;
use App\Domains\Tenancy\Models\DemoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoRequest>
 */
class DemoRequestFactory extends Factory
{
    protected $model = DemoRequest::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+52 81 '.fake()->numerify('#### ####'),
            'fleet_size' => fake()->randomElement(DemoRequest::FLEET_SIZES),
            'message' => fake()->sentence(),
            'status' => DemoRequestStatus::New,
        ];
    }

    public function contacted(): static
    {
        return $this->state(fn () => [
            'status' => DemoRequestStatus::Contacted,
            'status_changed_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => DemoRequestStatus::Closed,
            'status_changed_at' => now(),
        ]);
    }
}
