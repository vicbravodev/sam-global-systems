<?php

namespace App\Domains\Decisions\Actions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EvaluateDecisionRules
{
    public function __construct(
        private readonly ApplyTenantRuleSet $applyTenantRuleSet,
        private readonly ResolveDecisionOutcome $resolveDecisionOutcome,
        private readonly ResolveEscalationPath $resolveEscalationPath,
        private readonly GenerateDecisionTrace $generateDecisionTrace,
    ) {}

    public function execute(AIEventEvaluation $eval, ?EventContextSnapshot $context = null): Decision
    {
        $existing = Decision::withoutGlobalScopes()
            ->where('ai_evaluation_id', $eval->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $context ??= EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $eval->normalized_event_id)
            ->first();

        return DB::transaction(function () use ($eval, $context) {
            $applied = $this->applyTenantRuleSet->execute($eval->team_id, $eval, $context);
            $matchedRules = $applied['matchedRules'];
            $ruleSet = $applied['ruleset'];

            $resolved = $this->resolveDecisionOutcome->execute($eval, $matchedRules);

            $priority = $this->mapPriority($eval, $resolved['requiresHumanReview']);

            $decision = Decision::create([
                'normalized_event_id' => $eval->normalized_event_id,
                'team_id' => $eval->team_id,
                'ai_evaluation_id' => $eval->id,
                'ruleset_id' => $ruleSet?->id,
                'decision_code' => $resolved['outcome']->code,
                'decision_reason' => $resolved['reason'],
                'priority_level' => $priority,
                'requires_human_review' => $resolved['requiresHumanReview'],
                'is_automated' => true,
                'outcome_id' => $resolved['outcome']->id,
                'context_snapshot_id' => $context?->id,
                'decided_at' => now(),
            ]);

            $this->resolveEscalationPath->execute($decision, $resolved['sourceRule']);
            $decision->refresh();

            $steps = $this->buildTraceSteps($eval, $matchedRules, $resolved);
            $this->generateDecisionTrace->execute($decision, $steps);

            DecisionMade::dispatch($decision);

            return $decision;
        });
    }

    private function mapPriority(AIEventEvaluation $eval, bool $requiresHumanReview): DecisionPriority
    {
        if ($requiresHumanReview) {
            return DecisionPriority::High;
        }

        return match ($eval->priority_level) {
            EvaluationPriority::Urgent => DecisionPriority::Urgent,
            EvaluationPriority::High => DecisionPriority::High,
            EvaluationPriority::Normal => DecisionPriority::Normal,
            EvaluationPriority::Low => DecisionPriority::Low,
        };
    }

    /**
     * @param  Collection<int, DecisionRule>  $matchedRules
     * @param  array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}  $resolved
     * @return array<int, array{source_type: DecisionSourceType, rule_code?: ?string, source_reference_id?: ?int, input?: array<string, mixed>, output?: array<string, mixed>, explanation?: ?string}>
     */
    private function buildTraceSteps(AIEventEvaluation $eval, $matchedRules, array $resolved): array
    {
        $steps = [];

        $steps[] = [
            'source_type' => DecisionSourceType::Ai,
            'source_reference_id' => $eval->id,
            'input' => [
                'classification' => $eval->classification->value,
                'confidence_score' => $eval->confidence_score,
                'risk_score' => $eval->risk_score,
                'priority_level' => $eval->priority_level->value,
            ],
            'output' => ['recommended_action' => $eval->recommended_action],
            'explanation' => 'AI evaluation captured.',
        ];

        foreach ($matchedRules as $rule) {
            $steps[] = [
                'source_type' => $rule->team_id !== null ? DecisionSourceType::TenantPolicy : DecisionSourceType::Rule,
                'rule_code' => $rule->code,
                'source_reference_id' => $rule->id,
                'input' => ['conditions' => $rule->conditions_json],
                'output' => [
                    'outcome_override' => $rule->outcome_override,
                    'stop_processing' => $rule->stop_processing,
                ],
                'explanation' => 'Rule '.$rule->code.' matched.',
            ];
        }

        $steps[] = [
            'source_type' => $resolved['sourceType'],
            'rule_code' => $resolved['sourceRule']?->code,
            'source_reference_id' => $resolved['sourceRule']?->id,
            'input' => [],
            'output' => [
                'outcome_code' => $resolved['outcome']->code,
                'requires_human_review' => $resolved['requiresHumanReview'],
            ],
            'explanation' => $resolved['reason'],
        ];

        return $steps;
    }
}
