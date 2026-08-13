<?php

namespace App\Domains\AI\Support;

use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * Decides whether a normalized event should be analyzed by the AI pipeline.
 *
 * Events whose category is already authoritatively classified upstream by the
 * provider (e.g. Samsara safety events: harsh braking, speeding, distraction)
 * skip AI evaluation — re-classifying them would be redundant and paid. They
 * are still persisted and feed correlation for high-value incidents (panic,
 * jamming) via the Context domain.
 */
class AIEvaluationGate
{
    /** @var array<int, string> */
    private array $skipCategories;

    /**
     * @param  array<int, string>|null  $skipCategories  Category codes to skip; defaults to config `ai.skip_evaluation_categories`.
     */
    public function __construct(?array $skipCategories = null)
    {
        if ($skipCategories === null) {
            /** @var array<int, string> $skipCategories */
            $skipCategories = config('ai.skip_evaluation_categories', []);
        }

        $this->skipCategories = $skipCategories;
    }

    public function shouldEvaluate(NormalizedEvent $event): bool
    {
        $categoryCode = $event->eventCategory?->code;

        if ($categoryCode === null) {
            return true;
        }

        return ! in_array($categoryCode, $this->skipCategories, true);
    }
}
