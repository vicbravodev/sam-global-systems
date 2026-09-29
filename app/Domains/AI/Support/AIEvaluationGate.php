<?php

namespace App\Domains\AI\Support;

use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;

/**
 * Decides whether a normalized event should be analyzed by the AI pipeline.
 *
 * Events whose category is already authoritatively classified upstream by the
 * provider (e.g. Samsara safety events: harsh braking, speeding, distraction)
 * skip AI evaluation — re-classifying them would be redundant and paid. So do
 * low-value event types (`ai.skip_evaluation_event_types`: geofence
 * entry/exit, idling, driving context…; never `unmapped`, which may be a new
 * provider alert still lacking a mapping rule), matched by event type code
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
        return $this->skipReason($event) === null;
    }

    /**
     * Why the event is skipped (`skip_type` | `skip_category`), or null when it
     * should be evaluated. Pure: same logic `shouldEvaluate` always had.
     */
    public function skipReason(NormalizedEvent $event): ?string
    {
        $typeCode = $event->eventType?->code;

        if ($typeCode !== null && in_array($typeCode, $this->skipEventTypes, true)) {
            return 'skip_type';
        }

        $categoryCode = $event->eventCategory?->code;

        if ($categoryCode !== null && in_array($categoryCode, $this->skipCategories, true)) {
            return 'skip_category';
        }

        return null;
    }

    /**
     * `shouldEvaluate` plus the narrative log line when the event is skipped.
     * `$stage` names the caller: context_listener | evaluate_job | reevaluate_job.
     */
    public function allows(NormalizedEvent $event, string $stage): bool
    {
        $reason = $this->skipReason($event);

        if ($reason === null) {
            return true;
        }

        SystemLog::skipped(
            'ai.gate.skipped',
            reason: $reason,
            input: [
                'normalized_event_id' => $event->id,
                'event_type_code' => $event->eventType?->code,
                'category_code' => $event->eventCategory?->code,
                'stage' => $stage,
            ],
            calc: ['config_key' => $reason === 'skip_type' ? 'ai.skip_evaluation_event_types' : 'ai.skip_evaluation_categories'],
            result: ['evaluated' => false, 'decision_engine_runs' => false],
        );

        return false;
    }
}
