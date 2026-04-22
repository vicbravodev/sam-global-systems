<?php

namespace Database\Factories\Domains\Ingestion;

use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Models\RawEventAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawEventAttachment>
 */
class RawEventAttachmentFactory extends Factory
{
    protected $model = RawEventAttachment::class;

    public function definition(): array
    {
        return [
            'raw_event_id' => RawEvent::factory(),
            'attachment_type' => AttachmentType::Snapshot,
            'storage_path' => 'teams/1/raw-events/1/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1024, 10485760),
            'metadata_json' => null,
        ];
    }

    public function snapshot(): static
    {
        return $this->state(fn () => [
            'attachment_type' => AttachmentType::Snapshot,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function clip(): static
    {
        return $this->state(fn () => [
            'attachment_type' => AttachmentType::Clip,
            'mime_type' => 'video/mp4',
        ]);
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'attachment_type' => AttachmentType::Image,
            'mime_type' => 'image/png',
        ]);
    }

    public function document(): static
    {
        return $this->state(fn () => [
            'attachment_type' => AttachmentType::Document,
            'mime_type' => 'application/pdf',
        ]);
    }
}
