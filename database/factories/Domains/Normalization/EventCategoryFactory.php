<?php

namespace Database\Factories\Domains\Normalization;

use App\Domains\Normalization\Models\EventCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventCategory>
 */
class EventCategoryFactory extends Factory
{
    protected $model = EventCategory::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function safety(): static
    {
        return $this->state(fn () => [
            'code' => 'safety',
            'name' => 'Safety',
        ]);
    }

    public function emergency(): static
    {
        return $this->state(fn () => [
            'code' => 'emergency',
            'name' => 'Emergency',
        ]);
    }

    public function compliance(): static
    {
        return $this->state(fn () => [
            'code' => 'compliance',
            'name' => 'Compliance',
        ]);
    }

    public function operational(): static
    {
        return $this->state(fn () => [
            'code' => 'operational',
            'name' => 'Operational',
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => [
            'code' => 'maintenance',
            'name' => 'Maintenance',
        ]);
    }
}
