<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingRate>
 */
class BillingRateFactory extends Factory
{
    protected $model = BillingRate::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'usage_meter_id' => UsageMeter::factory(),
            'included_quantity' => fake()->numberBetween(100, 10000),
            'overage_unit_price' => fake()->randomFloat(4, 0.0001, 0.1),
            'billing_model' => BillingModel::FlatPlusOverage,
            'tiers_json' => null,
        ];
    }

    public function metered(): static
    {
        return $this->state(fn () => [
            'billing_model' => BillingModel::Metered,
            'included_quantity' => 0,
        ]);
    }

    public function includedOnly(int $quantity): static
    {
        return $this->state(fn () => [
            'billing_model' => BillingModel::IncludedOnly,
            'included_quantity' => $quantity,
            'overage_unit_price' => 0,
        ]);
    }
}
