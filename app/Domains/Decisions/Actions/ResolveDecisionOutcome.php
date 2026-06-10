<?php

namespace App\Domains\Decisions\Actions;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Models\Decision;
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
        return $this->guardMediaContradiction($eval, $this->resolve($eval, $matchedRules));
    }

    /**
     * @param  Collection<int, DecisionRule>  $matchedRules
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}
     */
    private function resolve(AIEventEvaluation $eval, Collection $matchedRules): array
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

    /**
     * Footage that contradicts an event the engine already acted on must land
     * in front of a human instead of silently degrading the decision: a prior
     * actionable decision usually means an open incident, and an IGNORE /
     * LOG_ONLY re-decision would orphan it. The incident is never auto-closed.
     *
     * @param  array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}  $resolved
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}
     */
    private function guardMediaContradiction(AIEventEvaluation $eval, array $resolved): array
    {
        $code = DecisionOutcomeCode::tryFrom((string) $resolved['outcome']->code);

        if ($code === null || ! $code->isTerminal()) {
            return $resolved;
        }

        if ($this->latestMediaAssessmentResult($eval) !== MediaAssessmentResult::ContradictsEvent) {
            return $resolved;
        }

        $hadActionableDecision = Decision::withoutGlobalScopes()
            ->where('normalized_event_id', $eval->normalized_event_id)
            ->whereIn('decision_code', [
                DecisionOutcomeCode::Alert->value,
                DecisionOutcomeCode::Incident->value,
                DecisionOutcomeCode::Escalate->value,
                DecisionOutcomeCode::RequireHumanReview->value,
            ])
            ->exists();

        if (! $hadActionableDecision) {
            return $resolved;
        }

        $outcome = DecisionOutcome::firstOrCreate(
            ['code' => DecisionOutcomeCode::RequireHumanReview->value],
            ['name' => 'Require Human Review', 'is_terminal' => false],
        );

        return [
            'outcome' => $outcome,
            'sourceType' => DecisionSourceType::Fallback,
            'sourceRule' => null,
            'reason' => 'Media contradicts the event but a prior decision already acted on it; '
                .'downgrade to '.$resolved['outcome']->code.' blocked pending human review.',
            'requiresHumanReview' => true,
        ];
    }

    private function latestMediaAssessmentResult(AIEventEvaluation $eval): ?MediaAssessmentResult
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
            return $result;
        }

        return is_string($result) ? MediaAssessmentResult::tryFrom($result) : null;
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
