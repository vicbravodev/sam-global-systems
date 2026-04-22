<?php

namespace Database\Factories\Domains\Integrations;

use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider_id' => IntegrationProvider::factory(),
            'event_type' => fake()->randomElement(['vehicle.updated', 'driver.created', 'alert.triggered']),
            'payload_json' => ['event' => 'test', 'data' => ['id' => fake()->uuid()]],
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => WebhookEventStatus::Processed,
            'processed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => WebhookEventStatus::Failed,
            'error_message' => 'Processing error',
        ]);
    }

    public function invalidSignature(): static
    {
        return $this->state(fn () => [
            'status' => WebhookEventStatus::InvalidSignature,
        ]);
    }
}
