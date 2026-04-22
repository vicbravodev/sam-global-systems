<?php

namespace Database\Factories\Domains\Ingestion;

use App\Domains\Ingestion\Models\EventReceipt;
use App\Domains\Ingestion\Models\RawEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventReceipt>
 */
class EventReceiptFactory extends Factory
{
    protected $model = EventReceipt::class;

    public function definition(): array
    {
        return [
            'raw_event_id' => RawEvent::factory(),
            'received_via' => 'webhook',
            'request_id' => Str::uuid()->toString(),
            'source_ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'http_status_returned' => 200,
            'signature_valid' => true,
            'received_at' => now(),
            'metadata_json' => null,
        ];
    }

    public function viaPolling(): static
    {
        return $this->state(fn () => [
            'received_via' => 'polling',
            'source_ip' => null,
            'user_agent' => null,
        ]);
    }

    public function withInvalidSignature(): static
    {
        return $this->state(fn () => [
            'signature_valid' => false,
        ]);
    }
}
