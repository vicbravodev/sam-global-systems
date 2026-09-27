<?php

namespace App\Domains\AI\Support;

use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * Decides whether a normalized event should be analyzed by the AI pipeline.
 *
 * Events whose category is already authoritatively classified upstream by the
 * provider (e.g. Samsara safety events: harsh braking, speeding, distraction)
 * skip AI evaluation — re-classifying them would be redundant and paid. So do
 * low-value event types (`ai.skip_evaluation_event_types`: geofence
 * entry/exit, idling, driving context, unmapped…), matched by event type code
 * regardless of category. Skipped events are still persisted and feed
 * correlation for high-value incidents (panic, jamming) via the Context
 * domain.
 *
 * Consequence to keep in mind: the decision engine only runs on
 * `AIEvaluationCompleted` (`RunDecisionEngineOnAIEvaluationCompleted`), so a
 * skipped event produces NO decision and therefore no incident — the same
 * behaviour skipped categories (safety, maintenance) already had.
 */
class AIEvaluationGate
{
    /** @var array<int, string> */
    private array $skipCategories;

    /** @var array<int, string> */
    private array $skipEventTypes;

    /**
     * @param  array<int, string>|null  $skipCategories  Category codes to skip; defaults to config `ai.skip_evaluation_categories`.
     * @param  array<int, string>|null  $skipEventTypes  Event type codes to skip; defaults to config `ai.skip_evaluation_event_types`.
     */
    public function __construct(?array $skipCategories = null, ?array $skipEventTypes = null)
    {
        if ($skipCategories === null) {
            /** @var array<int, string> $skipCategories */
            $skipCategories = config('ai.skip_evaluation_categories', []);
        }

        if ($skipEventTypes === null) {
            /** @var array<int, string> $skipEventTypes */
            $skipEventTypes = config('ai.skip_evaluation_event_types', []);
        }

        $this->skipCategories = $skipCategories;
        $this->skipEventTypes = $skipEventTypes;
    }

    public function shouldEvaluate(NormalizedEvent $event): bool
    {
        $typeCode = $event->eventType?->code;

        if ($typeCode !== null && in_array($typeCode, $this->skipEventTypes, true)) {
            return false;
        }

        $categoryCode = $event->eventCategory?->code;

        if ($categoryCode === null) {
            return true;
        }

        return ! in_array($categoryCode, $this->skipCategories, true);
    }
}
