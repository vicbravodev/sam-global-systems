<?php

namespace App\Domains\Decisions\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use App\Domains\Decisions\Support\RuleConditionEvaluator;
use Illuminate\Support\Collection;

class ApplyTenantRuleSet
{
    public function __construct(
        private readonly TenantDecisionRulesResolver $rulesResolver,
        private readonly RuleConditionEvaluator $conditionEvaluator,
        private readonly DecisionFactsBuilder $factsBuilder,
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

        $facts = $this->factsBuilder->build($eval, $context);

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
}
