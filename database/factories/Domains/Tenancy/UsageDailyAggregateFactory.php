<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Models\UsageDailyAggregate;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageDailyAggregate>
 */
class UsageDailyAggregateFactory extends Factory
{
    protected $model = UsageDailyAggregate::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'usage_meter_id' => UsageMeter::factory(),
            'day' => now()->toDateString(),
            'quantity_sum' => fake()->numberBetween(0, 1000),
            'quantity_max' => fake()->numberBetween(0, 100),
        ];
    }
}
