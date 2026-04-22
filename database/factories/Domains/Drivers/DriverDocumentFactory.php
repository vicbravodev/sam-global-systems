<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\DocumentStatus;
use App\Domains\Drivers\Enums\DocumentType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverDocument>
 */
class DriverDocumentFactory extends Factory
{
    protected $model = DriverDocument::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'document_type' => DocumentType::License,
            'document_number' => fake()->bothify('DOC-######'),
            'issued_at' => now()->subYear(),
            'expires_at' => now()->addYear(),
            'status' => DocumentStatus::Valid,
        ];
    }

    public function license(): static
    {
        return $this->state(fn () => [
            'document_type' => DocumentType::License,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subMonth(),
            'status' => DocumentStatus::Expired,
        ]);
    }

    public function pendingRenewal(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::PendingRenewal,
        ]);
    }

    public function medicalCert(): static
    {
        return $this->state(fn () => [
            'document_type' => DocumentType::MedicalCert,
        ]);
    }
}
