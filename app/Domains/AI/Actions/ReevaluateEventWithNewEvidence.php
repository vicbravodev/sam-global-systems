<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;
use RuntimeException;

class ReevaluateEventWithNewEvidence
{
    public function __construct(
        private readonly EvaluateEventWithAI $evaluate,
    ) {}

    /**
     * Create a NEW evaluation with incremented version. Does NOT mutate
     * previous evaluations — full history is preserved.
     */
    public function execute(NormalizedEvent $event, string $triggerType): AIEventEvaluation
    {
        $context = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        if ($context === null) {
            throw new RuntimeException(
                "Cannot reevaluate event {$event->id}: no EventContextSnapshot found."
            );
        }

        $lastVersion = (int) AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->max('evaluation_version');

        $profile = OperationalContextProfile::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        return $this->evaluate->execute(
            event: $event,
            context: $context,
            profile: $profile,
            evaluationVersion: $lastVersion + 1,
        );
    }
}
