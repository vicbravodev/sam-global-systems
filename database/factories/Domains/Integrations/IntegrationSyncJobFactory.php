<?php

namespace Database\Factories\Domains\Integrations;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationSyncJob>
 */
class IntegrationSyncJobFactory extends Factory
{
    protected $model = IntegrationSyncJob::class;

    public function definition(): array
    {
        return [
            'tenant_integration_id' => TenantIntegration::factory(),
            'type' => SyncType::Full,
            'status' => SyncStatus::Pending,
            'records_processed' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SyncStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'records_processed' => fake()->numberBetween(10, 500),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => SyncStatus::Failed,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'error_message' => 'Provider API returned 503',
        ]);
    }

    public function incremental(): static
    {
        return $this->state(fn () => [
            'type' => SyncType::Incremental,
        ]);
    }
}
