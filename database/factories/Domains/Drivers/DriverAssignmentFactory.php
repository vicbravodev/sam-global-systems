<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverAssignment>
 */
class DriverAssignmentFactory extends Factory
{
    protected $model = DriverAssignment::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'driver_id' => Driver::factory(),
            'asset_id' => Asset::factory(),
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now(),
            'ended_at' => null,
            'source' => AssignmentSource::Integration,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => [
            'assignment_type' => AssignmentType::PrimaryDriver,
        ]);
    }

    public function secondary(): static
    {
        return $this->state(fn () => [
            'assignment_type' => AssignmentType::SecondaryDriver,
        ]);
    }

    public function temporary(): static
    {
        return $this->state(fn () => [
            'assignment_type' => AssignmentType::TemporaryOperator,
        ]);
    }

    public function responsibleParty(): static
    {
        return $this->state(fn () => [
            'assignment_type' => AssignmentType::ResponsibleParty,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'source' => AssignmentSource::Manual,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'ended_at' => now(),
        ]);
    }
}
