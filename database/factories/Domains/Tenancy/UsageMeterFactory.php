<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageMeter>
 */
class UsageMeterFactory extends Factory
{
    protected $model = UsageMeter::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'unit' => fake()->randomElement(['request', 'tokens', 'count', 'call', 'GB']),
            'aggregation_type' => AggregationType::Sum,
            'is_billable' => true,
            'reset_period' => ResetPeriod::Monthly,
        ];
    }

    public function nonBillable(): static
    {
        return $this->state(fn () => [
            'is_billable' => false,
        ]);
    }

    public function daily(): static
    {
        return $this->state(fn () => [
            'reset_period' => ResetPeriod::Daily,
        ]);
    }
}
