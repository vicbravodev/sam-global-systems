<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'base_price' => fake()->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'billing_cycle' => BillingCycle::Monthly,
            'is_active' => true,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn () => [
            'billing_cycle' => BillingCycle::Yearly,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
