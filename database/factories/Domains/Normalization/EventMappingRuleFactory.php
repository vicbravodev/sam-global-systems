<?php

namespace Database\Factories\Domains\Normalization;

use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMappingRule>
 */
class EventMappingRuleFactory extends Factory
{
    protected $model = EventMappingRule::class;

    public function definition(): array
    {
        return [
            'provider_id' => IntegrationProvider::factory(),
            'external_event_type' => fake()->word(),
            'external_conditions_json' => null,
            'mapped_event_type_id' => EventType::factory(),
            'mapped_category_id' => null,
            'mapped_severity_id' => null,
            'priority' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function withConditions(array $conditions): static
    {
        return $this->state(fn () => [
            'external_conditions_json' => $conditions,
        ]);
    }

    public function withPriority(int $priority): static
    {
        return $this->state(fn () => [
            'priority' => $priority,
        ]);
    }
}
