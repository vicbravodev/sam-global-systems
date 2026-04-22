<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSnapshot>
 */
class InvoiceSnapshotFactory extends Factory
{
    protected $model = InvoiceSnapshot::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);
        $overage = fake()->randomFloat(2, 0, 100);

        return [
            'team_id' => Team::factory(),
            'subscription_id' => Subscription::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'subtotal' => $subtotal,
            'overage_total' => $overage,
            'total' => $subtotal + $overage,
            'currency' => 'usd',
            'status' => InvoiceStatus::Draft,
            'breakdown_json' => null,
            'generated_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Paid,
        ]);
    }
}
