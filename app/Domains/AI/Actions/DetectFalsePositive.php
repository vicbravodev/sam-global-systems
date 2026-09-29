<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\FalsePositiveDetected;
use App\Domains\AI\Models\AIEventEvaluation;

class DetectFalsePositive
{
    public const float HIGH_CONFIDENCE_THRESHOLD = 0.85;

    /**
     * Returns true when the evaluation is a confident false positive and
     * dispatches `FalsePositiveDetected` so downstream domains can listen.
     */
    public function execute(AIEventEvaluation $evaluation): bool
    {
        $isFalsePositive = $this->isFalsePositive($evaluation);

        if ($isFalsePositive) {
            FalsePositiveDetected::dispatch($evaluation);
        }

        return $isFalsePositive;
    }

    /**
     * Condición pura (sin despachar nada): falso positivo con confianza alta.
     */
    public function isFalsePositive(AIEventEvaluation $evaluation): bool
    {
        return $evaluation->classification === EventClassification::FalsePositive
            && ($evaluation->confidence_score ?? 0.0) >= self::HIGH_CONFIDENCE_THRESHOLD;
    }
}
