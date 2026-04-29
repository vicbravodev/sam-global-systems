<?php

namespace App\Domains\Decisions\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\AI\Models\AIEventEvaluation;
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
        ];
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
