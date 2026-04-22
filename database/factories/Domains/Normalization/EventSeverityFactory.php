<?php

namespace Database\Factories\Domains\Normalization;

use App\Domains\Normalization\Models\EventSeverity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSeverity>
 */
class EventSeverityFactory extends Factory
{
    protected $model = EventSeverity::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(1),
            'label' => fake()->word(),
            'level' => fake()->numberBetween(1, 4),
            'color' => fake()->hexColor(),
            'response_sla_seconds' => null,
        ];
    }

    public function low(): static
    {
        return $this->state(fn () => [
            'code' => 'low',
            'label' => 'Low',
            'level' => 1,
            'color' => '#22c55e',
            'response_sla_seconds' => null,
        ]);
    }

    public function medium(): static
    {
        return $this->state(fn () => [
            'code' => 'medium',
            'label' => 'Medium',
            'level' => 2,
            'color' => '#f59e0b',
            'response_sla_seconds' => 3600,
        ]);
    }

    public function high(): static
    {
        return $this->state(fn () => [
            'code' => 'high',
            'label' => 'High',
            'level' => 3,
            'color' => '#f97316',
            'response_sla_seconds' => 900,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'code' => 'critical',
            'label' => 'Critical',
            'level' => 4,
            'color' => '#ef4444',
            'response_sla_seconds' => 300,
        ]);
    }
}
