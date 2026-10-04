<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HosDriverState>
 */
class HosDriverStateFactory extends Factory
{
    protected $model = HosDriverState::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'team_id' => fn (array $attributes) => Driver::withoutGlobalScopes()->whereKey($attributes['driver_id'])->value('team_id'),
            'duty_status' => HosDutyStatus::OffDuty,
            'status_since' => now(),
            'break_remaining_s' => 28800,
            'drive_remaining_s' => 39600,
            'shift_remaining_s' => 50400,
            'cycle_remaining_s' => 252000,
            'violation_s' => 0,
            'observed_at' => now(),
        ];
    }
}
