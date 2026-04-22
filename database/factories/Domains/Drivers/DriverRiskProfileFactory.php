<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\RiskLevel;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverRiskProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverRiskProfile>
 */
class DriverRiskProfileFactory extends Factory
{
    protected $model = DriverRiskProfile::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'risk_score' => fake()->randomFloat(2, 0, 100),
            'risk_level' => RiskLevel::Low,
            'incidents_count' => fake()->numberBetween(0, 5),
            'harsh_events_count' => fake()->numberBetween(0, 20),
            'fatigue_flags_count' => fake()->numberBetween(0, 10),
            'last_calculated_at' => now(),
        ];
    }

    public function low(): static
    {
        return $this->state(fn () => [
            'risk_score' => fake()->randomFloat(2, 0, 25),
            'risk_level' => RiskLevel::Low,
        ]);
    }

    public function medium(): static
    {
        return $this->state(fn () => [
            'risk_score' => fake()->randomFloat(2, 25.01, 50),
            'risk_level' => RiskLevel::Medium,
        ]);
    }

    public function high(): static
    {
        return $this->state(fn () => [
            'risk_score' => fake()->randomFloat(2, 50.01, 75),
            'risk_level' => RiskLevel::High,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'risk_score' => fake()->randomFloat(2, 75.01, 100),
            'risk_level' => RiskLevel::Critical,
        ]);
    }
}
