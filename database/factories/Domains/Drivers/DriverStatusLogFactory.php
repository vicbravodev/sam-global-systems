<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Enums\StatusSeverity;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverStatusLog>
 */
class DriverStatusLogFactory extends Factory
{
    protected $model = DriverStatusLog::class;

    public function definition(): array
    {
        $status = fake()->randomElement(DriverStatus::cases());

        return [
            'driver_id' => Driver::factory(),
            'status_code' => $status->value,
            'status_label' => $status->name,
            'severity' => StatusSeverity::Low,
            'effective_from' => now(),
            'effective_to' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => StatusSeverity::Critical,
        ]);
    }

    public function high(): static
    {
        return $this->state(fn () => [
            'severity' => StatusSeverity::High,
        ]);
    }
}
