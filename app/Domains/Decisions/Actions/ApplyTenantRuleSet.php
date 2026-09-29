<?php

namespace App\Domains\Decisions\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use App\Domains\Decisions\Support\RuleConditionEvaluator;
use App\Support\LoggableCode;
use App\Support\SystemLog;
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
        $ruleSet = $this->effectiveRuleSet($teamId);

        $matched = collect();

        if ($ruleSet === null) {
            SystemLog::degraded('decisions.ruleset.missing', reason: 'no_active_ruleset', input: [
                'ai_evaluation_id' => $eval->id,
                'default_ruleset_code' => LoggableCode::guard($this->rulesResolver->resolve($teamId)->defaultRuleSetCode),
            ], result: ['falls_back_to' => 'ai_mapping']);

            return ['ruleset' => null, 'matchedRules' => $matched];
        }

        $facts = $this->factsBuilder->build($eval, $context);

        // Un ruleset global puede contener reglas añadidas por varios tenants:
        // sólo cuentan las globales (team_id null) y las del team del evento.
        $rules = DecisionRule::query()
            ->where('ruleset_id', $ruleSet->id)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $evaluated = 0;
        $stoppedAt = null;

        foreach ($rules as $rule) {
            $evaluated++;

            $problems = $this->conditionEvaluator->problems($rule->conditions_json ?? []);

            if ($problems !== []) {
                SystemLog::degraded('decisions.rule.invalid', reason: $problems[0]['problem'], input: [
                    'rule_id' => $rule->id,
                    'rule_code' => LoggableCode::guard($rule->code),
                    'ruleset_id' => $ruleSet->id,
                    'rule_team_id' => $rule->team_id,
                ], calc: [
                    'problems' => $problems,
                    'problems_count' => count($problems),
                ], result: $this->invalidNodesResult($problems));
            }

            if ($this->conditionEvaluator->matches($rule->conditions_json ?? [], $facts)) {
                $matched->push($rule);
                if ($rule->stop_processing) {
                    $stoppedAt = $rule;
                    break;
                }
            }
        }

        SystemLog::ok('decisions.rules.evaluated', input: [
            'ai_evaluation_id' => $eval->id,
        ], calc: [
            'ruleset_id' => $ruleSet->id,
            'ruleset_scope' => $ruleSet->team_id !== null ? 'tenant' : 'global',
            'candidate_count' => $rules->count(),
            'evaluated_count' => $evaluated,
            'matched_rule_ids' => $matched->pluck('id')->all(),
            'matched' => $matched->map(fn (DecisionRule $r) => LoggableCode::guard($r->code))->all(),
            'stopped_at' => $stoppedAt !== null ? LoggableCode::guard($stoppedAt->code) : null,
            'facts' => [
                'classification' => $facts['classification'],
                'risk_score' => $facts['risk_score'],
                'confidence_score' => $facts['confidence_score'],
                'priority_level' => $facts['priority_level'],
                'media_assessment' => $facts['media_assessment'],
                'has_context_snapshot' => $facts['has_context_snapshot'],
                'event_type_code' => LoggableCode::guard($facts['event_type_code']),
            ],
        ], result: ['matched_count' => $matched->count()]);

        return ['ruleset' => $ruleSet, 'matchedRules' => $matched];
    }

    /**
     * The one ruleset the engine evaluates for this team: the team's own
     * active default, else the platform default. The rules page uses it to
     * tell the operator in which order each rule is actually checked.
     */
    public function effectiveRuleSet(int $teamId): ?RuleSet
    {
        $policy = $this->rulesResolver->resolve($teamId);

        return $this->resolveRuleSet($teamId, $policy->defaultRuleSetCode);
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
     * La afirmación global solo se hace cuando es cierta: todos los nodos
     * inválidos evalúan false. Si alguno lanza (`type_error`,
     * `error_exception`), cada problema lo dice en su `evaluates_as`.
     *
     * @param  list<array{evaluates_as: false|string}>  $problems
     * @return array<string, false>
     */
    private function invalidNodesResult(array $problems): array
    {
        foreach ($problems as $problem) {
            if ($problem['evaluates_as'] !== false) {
                return [];
            }
        }

        return ['invalid_nodes_evaluate_as' => false];
    }
}
