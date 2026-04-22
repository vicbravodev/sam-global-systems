<?php

namespace Database\Factories\Domains\Normalization;

use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NormalizedEvent>
 */
class NormalizedEventFactory extends Factory
{
    protected $model = NormalizedEvent::class;

    public function definition(): array
    {
        return [
            'raw_event_id' => RawEvent::factory(),
            'team_id' => Team::factory(),
            'provider_id' => null,
            'asset_id' => null,
            'driver_id' => null,
            'event_type_id' => EventType::factory(),
            'event_category_id' => EventCategory::factory(),
            'event_severity_id' => EventSeverity::factory(),
            'occurred_at' => now(),
            'processed_at' => now(),
            'payload_normalized_json' => ['event_type' => 'test', 'description' => fake()->sentence()],
            'context_json' => null,
            'status' => NormalizedEventStatus::Normalized,
        ];
    }

    public function normalized(): static
    {
        return $this->state(fn () => [
            'status' => NormalizedEventStatus::Normalized,
        ]);
    }

    public function enrichmentPending(): static
    {
        return $this->state(fn () => [
            'status' => NormalizedEventStatus::EnrichmentPending,
        ]);
    }

    public function enriched(): static
    {
        return $this->state(fn () => [
            'status' => NormalizedEventStatus::Enriched,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => NormalizedEventStatus::Failed,
        ]);
    }

    public function unmapped(): static
    {
        return $this->state(fn () => [
            'status' => NormalizedEventStatus::Unmapped,
        ]);
    }
}
