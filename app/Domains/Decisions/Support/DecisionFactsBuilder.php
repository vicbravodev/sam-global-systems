<?php

namespace App\Domains\Decisions\Support;

use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventContextSnapshot;
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
            'media_assessment' => $this->resolveMediaAssessment($eval),
        ];
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
