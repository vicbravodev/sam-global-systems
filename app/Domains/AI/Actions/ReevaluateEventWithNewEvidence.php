<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Events\AIReevaluationRequested;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Normalization\Models\NormalizedEvent;

class ReevaluateEventWithNewEvidence
{
    public function __construct(
        private readonly EvaluateEventWithAI $evaluateEventWithAI,
    ) {}

    /**
     * Re-run the evaluation pipeline for a normalized event, creating a new
     * versioned `AIEventEvaluation` record. The previous evaluation is never
     * mutated — callers can diff versions via `evaluation_version`.
     */
    public function execute(
        NormalizedEvent $event,
        ReevaluationTrigger $trigger,
        ?int $triggerReferenceId = null,
        ?string $reason = null,
    ): AIEventEvaluation {
        $request = $this->openRequest($event, $trigger, $triggerReferenceId, $reason);

        AIReevaluationRequested::dispatch($event->id, $trigger->value, $triggerReferenceId);

        $request->update(['status' => ReevaluationStatus::Processing]);

        $evaluation = $this->evaluateEventWithAI->execute($event);

        $request->update([
            'status' => ReevaluationStatus::Completed,
            'processed_at' => now(),
        ]);

        return $evaluation;
    }

    private function openRequest(
        NormalizedEvent $event,
        ReevaluationTrigger $trigger,
        ?int $triggerReferenceId,
        ?string $reason,
    ): AIReevaluationRequest {
        $existing = AIReevaluationRequest::where('normalized_event_id', $event->id)
            ->where('trigger_type', $trigger)
            ->whereIn('status', [ReevaluationStatus::Pending, ReevaluationStatus::Processing])
            ->first();

        if ($existing !== null) {
            $existing->update([
                'status' => ReevaluationStatus::Skipped,
                'processed_at' => now(),
                'reason' => trim((string) ($existing->reason ?? '').' | superseded by new request'),
            ]);
        }

        return AIReevaluationRequest::create([
            'normalized_event_id' => $event->id,
            'trigger_type' => $trigger,
            'trigger_reference_id' => $triggerReferenceId,
            'reason' => $reason,
            'status' => ReevaluationStatus::Pending,
            'requested_at' => now(),
        ]);
    }
}
