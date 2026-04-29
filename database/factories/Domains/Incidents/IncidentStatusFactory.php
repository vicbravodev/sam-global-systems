<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\IncidentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentStatus>
 */
class IncidentStatusFactory extends Factory
{
    protected $model = IncidentStatus::class;

    public function definition(): array
    {
        return [
            'code' => 'status_'.Str::random(8),
            'name' => fake()->word(),
            'description' => null,
            'is_terminal' => false,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'code' => IncidentStatusCode::Open->value,
            'name' => 'Open',
            'is_terminal' => false,
            'sort_order' => 1,
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn () => [
            'code' => IncidentStatusCode::InReview->value,
            'name' => 'In Review',
            'is_terminal' => false,
            'sort_order' => 2,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'code' => IncidentStatusCode::Resolved->value,
            'name' => 'Resolved',
            'is_terminal' => true,
            'sort_order' => 4,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'code' => IncidentStatusCode::Closed->value,
            'name' => 'Closed',
            'is_terminal' => true,
            'sort_order' => 5,
        ]);
    }
}
