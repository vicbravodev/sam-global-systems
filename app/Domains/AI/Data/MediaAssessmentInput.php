<?php

namespace App\Domains\AI\Data;

use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\Context\Enums\MediaType;

/**
 * Structured input sent to a `MediaAssessmentAgent`. Immutable DTO.
 */
final readonly class MediaAssessmentInput
{
    /**
     * @param  array<string, mixed>  $mediaMetadata
     * @param  array<string, mixed>  $eventContext
     */
    public function __construct(
        public int $teamId,
        public int $evaluationId,
        public int $mediaContextId,
        public MediaType $mediaType,
        public MediaAssessmentType $assessmentType,
        public ?string $storagePath,
        public ?string $mimeType,
        public ?int $sizeBytes,
        public ?int $durationSeconds,
        public array $mediaMetadata,
        public array $eventContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'evaluation_id' => $this->evaluationId,
            'event_media_context_id' => $this->mediaContextId,
            'media_type' => $this->mediaType->value,
            'assessment_type' => $this->assessmentType->value,
            'storage_path' => $this->storagePath,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'duration_seconds' => $this->durationSeconds,
            'media_metadata' => $this->mediaMetadata,
            'event_context' => $this->eventContext,
        ];
    }
}
