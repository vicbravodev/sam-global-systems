<?php

namespace App\Contracts\AI;

use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Data\MediaAssessmentOutput;

interface MediaAssessmentAgent
{
    /**
     * Run the multimodal assessment pipeline against a single media asset.
     *
     * Implementations may stream progress via broadcasting but MUST return a
     * deterministic `MediaAssessmentOutput` aggregating the final state.
     *
     * @throws \RuntimeException When the underlying provider fails in a way
     *                           the caller should treat as "unavailable" or
     *                           "low quality" without aborting the pipeline.
     */
    public function assess(MediaAssessmentInput $input): MediaAssessmentOutput;
}
