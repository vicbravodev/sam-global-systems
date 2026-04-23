<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\FalsePositiveDetected;
use App\Domains\AI\Models\AIEventEvaluation;

class DetectFalsePositive
{
    private const HIGH_CONFIDENCE_THRESHOLD = 0.85;

    /**
     * Returns true when the evaluation is a confident false positive and
     * dispatches `FalsePositiveDetected` so downstream domains can listen.
     */
    public function execute(AIEventEvaluation $evaluation): bool
    {
        $isFalsePositive = $evaluation->classification === EventClassification::FalsePositive
            && ($evaluation->confidence_score ?? 0.0) >= self::HIGH_CONFIDENCE_THRESHOLD;

        if ($isFalsePositive) {
            FalsePositiveDetected::dispatch($evaluation);
        }

        return $isFalsePositive;
    }
}
