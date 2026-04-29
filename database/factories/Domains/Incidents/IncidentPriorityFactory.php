<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Models\IncidentPriority;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentPriority>
 */
class IncidentPriorityFactory extends Factory
{
    protected $model = IncidentPriority::class;

    public function definition(): array
    {
        return [
            'code' => 'priority_'.Str::random(8),
            'name' => fake()->word(),
            'level' => fake()->numberBetween(1, 4),
            'sla_seconds' => fake()->randomElement([300, 1800, 3600, null]),
            'color' => fake()->hexColor(),
        ];
    }

    public function low(): static
    {
        return $this->state(fn () => [
            'code' => IncidentPriorityCode::Low->value,
            'name' => 'Low',
            'level' => 1,
            'sla_seconds' => null,
            'color' => '#6B7280',
        ]);
    }

    public function medium(): static
    {
        return $this->state(fn () => [
            'code' => IncidentPriorityCode::Medium->value,
            'name' => 'Medium',
            'level' => 2,
            'sla_seconds' => 3600,
            'color' => '#F59E0B',
        ]);
    }

    public function high(): static
    {
        return $this->state(fn () => [
            'code' => IncidentPriorityCode::High->value,
            'name' => 'High',
            'level' => 3,
            'sla_seconds' => 1800,
            'color' => '#EF4444',
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'code' => IncidentPriorityCode::Critical->value,
            'name' => 'Critical',
            'level' => 4,
            'sla_seconds' => 300,
            'color' => '#991B1B',
        ]);
    }
}
