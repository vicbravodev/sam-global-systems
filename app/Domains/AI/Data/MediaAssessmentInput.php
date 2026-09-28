<?php

namespace App\Domains\AI\Data;

use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\Context\Enums\MediaType;

/**
 * Structured input sent to a `MediaAssessmentAgent`. Immutable DTO.
 *
 * `teamId` and `storagePath` stay as properties (the SDK agent resolves the
 * attachment from them) but never travel in the payload the model sees.
 */
final readonly class MediaAssessmentInput
{
    /**
     * @param  array<string, mixed>  $mediaMetadata
     * @param  array<string, mixed>  $eventContext  Event type code/name, severity, occurred_at, evaluation verdict.
     * @param  string|null  $cameraSide  `road` (forward-facing) or `driver` (cabin-facing); null when unknown.
     * @param  int|null  $captureOffsetSeconds  Capture time minus event time (negative = before the event).
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
        public ?string $cameraSide = null,
        public ?int $captureOffsetSeconds = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'evaluation_id' => $this->evaluationId,
            'event_media_context_id' => $this->mediaContextId,
            'media_type' => $this->mediaType->value,
            'assessment_type' => $this->assessmentType->value,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'duration_seconds' => $this->durationSeconds,
            'camera_side' => $this->cameraSide,
            'capture_offset_seconds' => $this->captureOffsetSeconds,
            'media_metadata' => $this->mediaMetadata,
            'event_context' => $this->eventContext,
        ];
    }
}
