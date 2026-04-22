<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUsageCounter>
 */
class TenantUsageCounterFactory extends Factory
{
    protected $model = TenantUsageCounter::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'usage_meter_id' => UsageMeter::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'consumed_value' => 0,
            'included_value' => 0,
            'overage_value' => 0,
            'last_calculated_at' => null,
        ];
    }

    public function withOverage(int $consumed, int $included): static
    {
        return $this->state(fn () => [
            'consumed_value' => $consumed,
            'included_value' => $included,
            'overage_value' => max(0, $consumed - $included),
        ]);
    }
}
