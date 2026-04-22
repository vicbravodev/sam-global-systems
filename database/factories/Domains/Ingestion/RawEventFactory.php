<?php

namespace Database\Factories\Domains\Ingestion;

use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RawEvent>
 */
class RawEventFactory extends Factory
{
    protected $model = RawEvent::class;

    public function definition(): array
    {
        $payload = [
            'eventType' => 'AlertIncident',
            'eventId' => Str::uuid()->toString(),
            'eventTime' => now()->toIso8601String(),
            'data' => ['conditions' => [['description' => fake()->sentence()]]],
        ];

        return [
            'team_id' => Team::factory(),
            'event_source_id' => EventSource::factory(),
            'provider_id' => null,
            'external_event_id' => Str::uuid()->toString(),
            'event_type_raw' => 'AlertIncident',
            'payload_json' => $payload,
            'headers_json' => null,
            'received_at' => now(),
            'occurred_at' => null,
            'deduplication_key' => null,
            'status' => RawEventStatus::Received,
            'checksum' => hash('sha256', json_encode($payload)),
            'processing_attempts' => 0,
            'last_processing_attempt_at' => null,
        ];
    }

    public function received(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::Received,
        ]);
    }

    public function pendingProcessing(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::PendingProcessing,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::Processing,
            'processing_attempts' => 1,
            'last_processing_attempt_at' => now(),
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::Processed,
            'processing_attempts' => 1,
            'last_processing_attempt_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::Failed,
            'processing_attempts' => 3,
            'last_processing_attempt_at' => now(),
        ]);
    }

    public function duplicate(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::DuplicateDetected,
        ]);
    }

    public function invalidSignature(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::InvalidSignature,
        ]);
    }

    public function malformed(): static
    {
        return $this->state(fn () => [
            'status' => RawEventStatus::Malformed,
        ]);
    }
}
