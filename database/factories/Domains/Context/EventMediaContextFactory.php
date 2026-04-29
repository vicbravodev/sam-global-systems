<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Enums\MediaAvailabilityStatus;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaRole;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMediaContext>
 */
class EventMediaContextFactory extends Factory
{
    protected $model = EventMediaContext::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'normalized_event_id' => NormalizedEvent::factory(),
            'asset_id' => null,
            'provider_id' => null,
            'file_object_id' => null,
            'source_attachment_id' => null,
            'media_type' => MediaType::Snapshot,
            'media_role' => MediaRole::PrimaryEvidence,
            'media_url' => null,
            'thumbnail_url' => null,
            'storage_path' => 'teams/1/events/1/media/'.fake()->uuid().'.jpg',
            'duration_seconds' => null,
            'size_bytes' => fake()->numberBetween(1024, 5 * 1024 * 1024),
            'mime_type' => 'image/jpeg',
            'captured_at' => now(),
            'window_start' => null,
            'window_end' => null,
            'availability_status' => MediaAvailabilityStatus::Available,
            'retrieval_status' => MediaRetrievalStatus::Ready,
            'checksum' => hash('sha256', fake()->text(50)),
            'metadata_json' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'availability_status' => MediaAvailabilityStatus::Pending,
            'retrieval_status' => MediaRetrievalStatus::Requested,
        ]);
    }

    public function videoClip(): static
    {
        return $this->state(fn () => [
            'media_type' => MediaType::Clip,
            'mime_type' => 'video/mp4',
            'duration_seconds' => fake()->numberBetween(5, 120),
        ]);
    }

    public function audio(): static
    {
        return $this->state(fn () => [
            'media_type' => MediaType::Audio,
            'media_role' => MediaRole::CabinAudio,
            'mime_type' => 'audio/mpeg',
        ]);
    }
}
