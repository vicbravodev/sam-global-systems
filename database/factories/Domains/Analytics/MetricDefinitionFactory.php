<?php

namespace Database\Factories\Domains\Analytics;

use App\Domains\Analytics\Enums\MetricAggregationType;
use App\Domains\Analytics\Models\MetricDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricDefinition>
 */
class MetricDefinitionFactory extends Factory
{
    protected $model = MetricDefinition::class;

    public function definition(): array
    {
        $code = 'metric_'.fake()->unique()->numerify('######');

        return [
            'code' => $code,
            'name' => 'Metric '.$code,
            'description' => fake()->sentence(),
            'formula_description' => 'count(events where ...)',
            'unit' => 'count',
            'aggregation_type' => MetricAggregationType::Count,
            'source_modules_json' => ['tenancy'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
