<?php

namespace Database\Factories\Domains\Ingestion;

use App\Domains\Ingestion\Models\EventDeduplicationKey;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventDeduplicationKey>
 */
class EventDeduplicationKeyFactory extends Factory
{
    protected $model = EventDeduplicationKey::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'event_source_id' => EventSource::factory(),
            'deduplication_key' => Str::uuid()->toString(),
            'raw_event_id' => RawEvent::factory(),
            'first_seen_at' => now(),
            'expires_at' => now()->addHours(24),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'first_seen_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function neverExpires(): static
    {
        return $this->state(fn () => [
            'expires_at' => null,
        ]);
    }
}
