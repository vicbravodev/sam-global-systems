<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Events\AIReevaluationRequested;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\AI\Support\OperatorFeedbackCollector;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;

class ReevaluateEventWithNewEvidence
{
    public function __construct(
        private readonly EvaluateEventWithAI $evaluateEventWithAI,
        private readonly OperatorFeedbackCollector $operatorFeedback,
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

        // El feedback humano (veredictos del operador + motivos del diálogo
        // "Feedback", incluido el de esta misma solicitud) viaja al modelo.
        $evaluation = $this->evaluateEventWithAI->execute(
            $event,
            operatorFeedback: $this->operatorFeedback->collect($event),
        );

        $request->update([
            'status' => ReevaluationStatus::Completed,
            'processed_at' => now(),
        ]);

        SystemLog::ok(
            'ai.reevaluation.completed',
            input: [
                'normalized_event_id' => $event->id,
                'trigger_type' => $trigger->value,
                'trigger_reference_id' => $triggerReferenceId,
                'reason_present' => $reason !== null && $reason !== '',
            ],
            result: [
                'reevaluation_request_id' => $request->id,
                'evaluation_id' => $evaluation->id,
                'evaluation_version' => $evaluation->evaluation_version,
            ],
        );

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
            $previousStatus = $existing->status->value;

            $existing->update([
                'status' => ReevaluationStatus::Skipped,
                'processed_at' => now(),
                'reason' => trim(($existing->reason ?? '').' | superseded by new request'),
            ]);

            SystemLog::ok(
                'ai.reevaluation.superseded',
                input: ['normalized_event_id' => $event->id, 'trigger_type' => $trigger->value],
                result: ['superseded_request_id' => $existing->id, 'previous_status' => $previousStatus],
            );
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
