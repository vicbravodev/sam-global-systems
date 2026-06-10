<?php

namespace App\Domains\Decisions\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Decisions\Support\RuleConditionEvaluator;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Support\Collection;

class ApplyTenantRuleSet
{
    public function __construct(
        private readonly TenantDecisionRulesResolver $rulesResolver,
        private readonly RuleConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * @return array{ruleset: ?RuleSet, matchedRules: Collection<int, DecisionRule>}
     */
    public function execute(int $teamId, AIEventEvaluation $eval, ?EventContextSnapshot $context = null): array
    {
        $policy = $this->rulesResolver->resolve($teamId);
        $ruleSet = $this->resolveRuleSet($teamId, $policy->defaultRuleSetCode);

        $matched = collect();

        if ($ruleSet === null) {
            return ['ruleset' => null, 'matchedRules' => $matched];
        }

        $facts = $this->buildFacts($eval, $context);

        $rules = DecisionRule::query()
            ->where('ruleset_id', $ruleSet->id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->conditionEvaluator->matches($rule->conditions_json ?? [], $facts)) {
                $matched->push($rule);
                if ($rule->stop_processing) {
                    break;
                }
            }
        }

        return ['ruleset' => $ruleSet, 'matchedRules' => $matched];
    }

    private function resolveRuleSet(int $teamId, string $defaultCode): ?RuleSet
    {
        $tenantSet = RuleSet::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($tenantSet) {
            return $tenantSet;
        }

        return RuleSet::query()
            ->whereNull('team_id')
            ->where('is_active', true)
            ->where(function ($q) use ($defaultCode) {
                $q->where('is_default', true)->orWhere('code', $defaultCode);
            })
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFacts(AIEventEvaluation $eval, ?EventContextSnapshot $context): array
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
