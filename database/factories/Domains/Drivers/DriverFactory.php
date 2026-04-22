<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'team_id' => Team::factory(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "{$firstName} {$lastName}",
            'employee_code' => fake()->optional()->bothify('EMP-####'),
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => DriverStatus::Active,
        ]);
    }

    public function offDuty(): static
    {
        return $this->state(fn () => [
            'status' => DriverStatus::OffDuty,
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn () => [
            'status' => DriverStatus::Unavailable,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => DriverStatus::Suspended,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn () => [
            'status' => DriverStatus::UnderReview,
        ]);
    }
}
