<?php

namespace App\Domains\Decisions\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use Illuminate\Support\Collection;

class ResolveDecisionOutcome
{
    public function __construct(
        private readonly TenantDecisionRulesResolver $rulesResolver,
    ) {}

    /**
     * @param  Collection<int, DecisionRule>  $matchedRules
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}
     */
    public function execute(AIEventEvaluation $eval, Collection $matchedRules): array
    {
        $policy = $this->rulesResolver->resolve($eval->team_id);

        $confidence = (float) ($eval->confidence_score ?? 0.0);
        $requiresHumanReview = $confidence < $policy->humanReviewConfidenceThreshold;

        $hardSafetyRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->stop_processing && $rule->outcome_override !== null);

        if ($hardSafetyRule !== null && $hardSafetyRule->outcomeOverride) {
            return [
                'outcome' => $hardSafetyRule->outcomeOverride,
                'sourceType' => DecisionSourceType::Rule,
                'sourceRule' => $hardSafetyRule,
                'reason' => 'Hard safety rule matched: '.$hardSafetyRule->code,
                'requiresHumanReview' => $requiresHumanReview,
            ];
        }

        $tenantRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->team_id !== null && $rule->outcome_override !== null);

        if ($tenantRule !== null && $tenantRule->outcomeOverride) {
            return [
                'outcome' => $tenantRule->outcomeOverride,
                'sourceType' => DecisionSourceType::TenantPolicy,
                'sourceRule' => $tenantRule,
                'reason' => 'Tenant rule applied: '.$tenantRule->code,
                'requiresHumanReview' => $requiresHumanReview,
            ];
        }

        $globalRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->outcome_override !== null);

        if ($globalRule !== null && $globalRule->outcomeOverride) {
            return [
                'outcome' => $globalRule->outcomeOverride,
                'sourceType' => DecisionSourceType::Rule,
                'sourceRule' => $globalRule,
                'reason' => 'Rule applied: '.$globalRule->code,
                'requiresHumanReview' => $requiresHumanReview,
            ];
        }

        $aiOutcomeCode = $this->mapClassificationToOutcome($eval, $requiresHumanReview);
        $aiOutcome = DecisionOutcome::firstWhere('code', $aiOutcomeCode->value);

        if ($aiOutcome !== null) {
            return [
                'outcome' => $aiOutcome,
                'sourceType' => DecisionSourceType::Ai,
                'sourceRule' => null,
                'reason' => 'Mapped from AI classification '.$eval->classification->value,
                'requiresHumanReview' => $requiresHumanReview,
            ];
        }

        $fallback = DecisionOutcome::firstOrCreate(
            ['code' => DecisionOutcomeCode::LogOnly->value],
            ['name' => 'Log Only', 'is_terminal' => true],
        );

        return [
            'outcome' => $fallback,
            'sourceType' => DecisionSourceType::Fallback,
            'sourceRule' => null,
            'reason' => 'No rules matched; fallback to LOG_ONLY',
            'requiresHumanReview' => $requiresHumanReview,
        ];
    }

    private function mapClassificationToOutcome(AIEventEvaluation $eval, bool $requiresHumanReview): DecisionOutcomeCode
    {
        if ($requiresHumanReview && $eval->classification->isActionable()) {
            return DecisionOutcomeCode::RequireHumanReview;
        }

        $risk = (float) ($eval->risk_score ?? 0.0);

        return match ($eval->classification) {
            EventClassification::RealEvent => $risk >= 0.85
                ? DecisionOutcomeCode::Escalate
                : ($risk >= 0.6 ? DecisionOutcomeCode::Incident : DecisionOutcomeCode::Alert),
            EventClassification::Unclear => DecisionOutcomeCode::RequireHumanReview,
            EventClassification::PendingEvidence => DecisionOutcomeCode::RequireHumanReview,
            EventClassification::FalsePositive, EventClassification::Duplicate => DecisionOutcomeCode::Ignore,
            EventClassification::Noise => DecisionOutcomeCode::LogOnly,
        };
    }
}
