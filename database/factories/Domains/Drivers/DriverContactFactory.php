<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverContact>
 */
class DriverContactFactory extends Factory
{
    protected $model = DriverContact::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'contact_type' => ContactType::MobilePhone,
            'label' => 'Personal',
            'value' => fake()->phoneNumber(),
            'is_primary' => false,
            'is_emergency' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => [
            'is_primary' => true,
        ]);
    }

    public function emergency(): static
    {
        return $this->state(fn () => [
            'is_emergency' => true,
            'contact_type' => ContactType::EmergencyContact,
        ]);
    }

    public function supervisor(): static
    {
        return $this->state(fn () => [
            'contact_type' => ContactType::SupervisorContact,
            'label' => 'Supervisor',
        ]);
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'contact_type' => ContactType::Email,
            'value' => fake()->safeEmail(),
        ]);
    }
}
