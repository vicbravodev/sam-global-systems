<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\FalsePositiveDetected;
use App\Domains\AI\Models\AIEventEvaluation;

class DetectFalsePositive
{
    /**
     * Return true when heuristic signals + AI confidence agree that the event
     * should be marked as a false positive. Dispatches FalsePositiveDetected
     * when the verdict is reached.
     */
    public function execute(AIEventEvaluation $evaluation): bool
    {
        $isFalsePositive = $evaluation->classification === EventClassification::FalsePositive
            && (float) $evaluation->confidence_score >= 0.60;

        if ($isFalsePositive) {
            FalsePositiveDetected::dispatch($evaluation);
        }

        return $isFalsePositive;
    }
}
