<?php

namespace Database\Factories\Domains\Normalization;

use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
{
    protected $model = EventType::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'category_id' => EventCategory::factory(),
            'default_severity_id' => EventSeverity::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withoutDefaultSeverity(): static
    {
        return $this->state(fn () => [
            'default_severity_id' => null,
        ]);
    }
}
