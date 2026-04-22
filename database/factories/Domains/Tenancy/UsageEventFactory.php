<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => null,
            'usage_meter_id' => UsageMeter::factory(),
            'event_key' => Str::uuid()->toString(),
            'quantity' => fake()->numberBetween(1, 100),
            'metadata_json' => null,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
        ];
    }
}
