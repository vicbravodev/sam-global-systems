<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Data\MediaAssessmentOutput;
use App\Domains\AI\Enums\MediaAssessmentResult;

/**
 * SPEC-09-MULTIMODAL-DEFERRED: deterministic stand-in for the Laravel AI SDK
 * multimodal agent.
 *
 * Returns a structured outcome derived purely from the input metadata so the
 * rest of the multimodal pipeline (persistence, usage metering, classification
 * fusion) can run end-to-end in tests and in environments without the real
 * SDK configured. When the multimodal SDK lands, bind `MediaAssessmentAgent`
 * to its implementation in `AIServiceProvider::register()`; no other code
 * changes.
 */
class NullMediaAssessmentAgent implements MediaAssessmentAgent
{
    public bool $shouldFail = false;

    public MediaAssessmentResult $forcedResult = MediaAssessmentResult::ConfirmsEvent;

    public float $forcedConfidence = 0.80;

    public function assess(MediaAssessmentInput $input): MediaAssessmentOutput
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('NullMediaAssessmentAgent simulated failure');
        }

        $signals = [
            'media_type' => $input->mediaType->value,
            'assessment_type' => $input->assessmentType->value,
            'has_storage_path' => $input->storagePath !== null,
            'mime_type' => $input->mimeType,
            'size_bytes' => $input->sizeBytes,
            'duration_seconds' => $input->durationSeconds,
        ];

        return new MediaAssessmentOutput(
            result: $this->forcedResult,
            confidenceScore: $this->forcedConfidence,
            summaryText: 'Evaluación de medios determinista generada por NullMediaAssessmentAgent.',
            extractedSignals: $signals,
            modelUsed: 'null-media-agent:1.0',
            inputTokens: 250,
            outputTokens: 80,
            latencyMs: 5,
            costEstimate: 0.0,
        );
    }
}
