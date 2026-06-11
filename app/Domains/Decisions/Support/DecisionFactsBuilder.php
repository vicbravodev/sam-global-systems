<?php

namespace App\Domains\Decisions\Support;

use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Normalization\Models\EventType;

/**
 * Single source of the fact map that tenant decision rules evaluate against.
 * Extracted from ApplyTenantRuleSet so the rule tester (and any future
 * consumer) builds identical facts to the live decision pipeline.
 */
class DecisionFactsBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(AIEventEvaluation $eval, ?EventContextSnapshot $context = null): array
    {
        $event = $eval->normalizedEvent;
        $vision = $this->resolveMediaVisionFacts($eval);

        return [
            'classification' => $eval->classification->value,
            'risk_score' => (float) ($eval->risk_score ?? 0.0),
            'confidence_score' => (float) ($eval->confidence_score ?? 0.0),
            'priority_level' => $eval->priority_level->value,
            'is_real_event' => $eval->is_real_event,
            'requires_action' => $eval->requires_action,
            'event_type' => $event?->event_type_id,
            'event_type_code' => $this->resolveEventTypeCode($event?->event_type_id),
            'team_id' => $eval->team_id,
            'has_context_snapshot' => $context !== null,
            // False-alarm validation facts (Roadmap B6-P7). Sourced from the
            // context snapshot's signals, so tenant rules can degrade a clean
            // false alarm to REVIEW without ever touching the hard defaults.
            'external_resolved' => (bool) (($context?->signals_json ?? [])['external_resolved'] ?? false),
            'parked_at_base' => (bool) (($context?->signals_json ?? [])['parked_at_base'] ?? false),
            'repeated_panic_count_24h' => (int) (($context?->recent_history_snapshot_json ?? [])['repeated_panic_count_24h'] ?? 0),
            // Safety correlation around the event (Roadmap V2-A2).
            'harsh_driving_near_event' => (bool) (($context?->signals_json ?? [])['harsh_driving_near_event'] ?? false),
            'nearby_safety_events_count' => (int) (($context?->recent_history_snapshot_json ?? [])['nearby_safety_events_count'] ?? 0),
            // After-hours context (Roadmap V2-C2).
            'outside_operating_hours' => (bool) (($context?->signals_json ?? [])['outside_operating_hours'] ?? false),
            'media_assessment' => $this->resolveMediaAssessment($eval),
            // Structured vision facts extracted per-media by the multimodal
            // inspector (Roadmap V2-A1): aggregated across every assessment of
            // the event so rules can react to what the cameras actually saw.
            'media_passenger_detected' => $vision['passenger_detected'],
            'media_visible_threat' => $vision['visible_threat'],
            'media_persons_visible_count' => $vision['persons_visible_count'],
            'media_cabin_appears_normal' => $vision['cabin_appears_normal'],
            // Operator phone verification (Roadmap V2-A3): what the human on
            // call answered via DTMF, if the call already happened.
            'operator_call_outcome' => $this->resolveOperatorCallOutcome($eval),
        ];
    }

    /**
     * Latest DTMF verification outcome across the incidents opened for this
     * event: `confirmed_real`, `confirmed_false` or `no_answer` — null while
     * no call has concluded.
     */
    private function resolveOperatorCallOutcome(AIEventEvaluation $eval): ?string
    {
        $incidentIds = Incident::withoutGlobalScopes()
            ->where('related_event_id', $eval->normalized_event_id)
            ->select('id');

        $outcome = IncidentCallVerification::withoutGlobalScopes()
            ->whereIn('incident_id', $incidentIds)
            ->whereNotNull('outcome')
            ->orderByDesc('responded_at')
            ->orderByDesc('id')
            ->value('outcome');

        if ($outcome instanceof CallVerificationOutcome) {
            return $outcome->value;
        }

        return is_string($outcome) && $outcome !== '' ? $outcome : null;
    }

    /**
     * Latest multimodal media assessment for the event, if any — lets tenant
     * rules react to what the footage showed (e.g. clear_cabin). Resolved
     * across every evaluation version of the same normalized event: deferred
     * media is assessed under the evaluation that was current when it landed,
     * while re-evaluations create fresh versions that must still see it.
     */
    private function resolveMediaAssessment(AIEventEvaluation $eval): ?string
    {
        $evaluationIds = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $eval->normalized_event_id)
            ->select('id');

        $result = AIMediaAssessment::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->value('result');

        if ($result instanceof MediaAssessmentResult) {
            return $result->value;
        }

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * Aggregate the structured vision signals across every media assessment
     * of the event (all evaluation versions). Alarming evidence dominates:
     * any assessment that saw a passenger or a threat sets the fact true, any
     * abnormal cabin wins over normal ones, and the person count is the
     * maximum observed. Null means no assessment could determine the signal.
     *
     * @return array{passenger_detected: bool|null, visible_threat: bool|null, persons_visible_count: int|null, cabin_appears_normal: bool|null}
     */
    private function resolveMediaVisionFacts(AIEventEvaluation $eval): array
    {
        $evaluationIds = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $eval->normalized_event_id)
            ->select('id');

        $signalSets = AIMediaAssessment::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->whereNotNull('extracted_signals_json')
            ->pluck('extracted_signals_json');

        $facts = [
            'passenger_detected' => null,
            'visible_threat' => null,
            'persons_visible_count' => null,
            'cabin_appears_normal' => null,
        ];

        foreach ($signalSets as $signals) {
            $signals = (array) $signals;

            $facts['passenger_detected'] = $this->mergeAlarming($facts['passenger_detected'], $signals['passenger_detected'] ?? null);
            $facts['visible_threat'] = $this->mergeAlarming($facts['visible_threat'], $signals['visible_threat'] ?? null);

            if (is_numeric($signals['persons_visible_count'] ?? null)) {
                $facts['persons_visible_count'] = max((int) $signals['persons_visible_count'], $facts['persons_visible_count'] ?? 0);
            }

            $normal = $signals['cabin_appears_normal'] ?? null;

            if (is_bool($normal)) {
                $facts['cabin_appears_normal'] = $facts['cabin_appears_normal'] === false ? false : $normal;
            }
        }

        return $facts;
    }

    private function mergeAlarming(?bool $current, mixed $candidate): ?bool
    {
        if (! is_bool($candidate)) {
            return $current;
        }

        return $current === true || $candidate;
    }

    private function resolveEventTypeCode(?int $eventTypeId): ?string
    {
        if ($eventTypeId === null) {
            return null;
        }

        return EventType::query()
            ->whereKey($eventTypeId)
            ->value('code');
    }
}
