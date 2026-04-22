<?php

namespace Database\Factories\Domains\Ingestion;

use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSource>
 */
class EventSourceFactory extends Factory
{
    protected $model = EventSource::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider_id' => IntegrationProvider::factory(),
            'tenant_integration_id' => null,
            'source_type' => EventSourceType::Webhook,
            'source_name' => fake()->words(2, true),
            'status' => EventSourceStatus::Active,
            'config_json' => null,
        ];
    }

    public function webhook(): static
    {
        return $this->state(fn () => [
            'source_type' => EventSourceType::Webhook,
            'source_name' => 'webhook',
        ]);
    }

    public function polling(): static
    {
        return $this->state(fn () => [
            'source_type' => EventSourceType::Polling,
            'source_name' => 'polling',
        ]);
    }

    public function batchImport(): static
    {
        return $this->state(fn () => [
            'source_type' => EventSourceType::BatchImport,
            'source_name' => 'batch_import',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => EventSourceStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => EventSourceStatus::Inactive,
        ]);
    }
}
