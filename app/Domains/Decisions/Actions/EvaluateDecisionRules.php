<?php

namespace App\Domains\Decisions\Actions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Support\LoggableCode;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
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
        $existing = Decision::query()
            ->where('ai_evaluation_id', $eval->id)
            ->first();

        if ($existing !== null) {
            SystemLog::skipped('decisions.decision.already_exists', reason: 'decision_exists', input: [
                'ai_evaluation_id' => $eval->id,
                'stage' => 'evaluate_rules',
            ], result: ['decision_id' => $existing->id]);

            return $existing;
        }

        $context ??= EventContextSnapshot::query()
            ->where('normalized_event_id', $eval->normalized_event_id)
            ->first();

        [$decision, $narrative] = DB::transaction(function () use ($eval, $context) {
            $applied = $this->applyTenantRuleSet->execute($eval->team_id, $eval, $context);
            $matchedRules = $applied['matchedRules'];
            $ruleSet = $applied['ruleset'];

            $resolved = $this->resolveDecisionOutcome->execute($eval, $matchedRules);

            // An outcome that *is* the human-review queue always flags the
            // decision for review, even when the AI confidence alone would
            // not have (e.g. a tenant false-alarm rule degrading to REVIEW).
            $reviewByResolver = $resolved['requiresHumanReview'];
            $reviewByOutcome = $resolved['outcome']->code === DecisionOutcomeCode::RequireHumanReview->value;
            $resolved['requiresHumanReview'] = $resolved['requiresHumanReview']
                || $reviewByOutcome;

            $mappedPriority = $this->mapPriority($eval, $resolved['requiresHumanReview']);
            $priority = $mappedPriority;
            $criticalBump = false;

            // Un evento crítico nunca sale con prioridad por debajo de High,
            // aunque la IA lo haya puntuado como falso positivo de baja
            // prioridad (el piso de seguridad ya lo subió a INCIDENT).
            if (in_array($priority, [DecisionPriority::Low, DecisionPriority::Normal], true)
                && $this->resolveDecisionOutcome->isCriticalSeverity($eval)) {
                $priority = DecisionPriority::High;
                $criticalBump = true;
            }

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

            $policy = $this->resolveEscalationPath->execute($decision, $resolved['sourceRule']);
            $decision->refresh();

            $steps = $this->buildTraceSteps($eval, $matchedRules, $resolved);
            $this->generateDecisionTrace->execute($decision, $steps);

            PipelineTrace::add(['decision_id' => $decision->id]);

            DecisionMade::dispatch($decision);

            $narrative = [
                'applied' => $applied,
                'resolved' => $resolved,
                'review_by_resolver' => $reviewByResolver,
                'review_by_outcome' => $reviewByOutcome,
                'mapped_priority' => $mappedPriority,
                'critical_bump' => $criticalBump,
                'policy' => $policy,
                'trace_steps_count' => count($steps),
            ];

            return [$decision, $narrative];
        });

        // Todo después del commit: si la transacción revierte, nada de esto
        // pasó y ninguna línea lo afirma.
        $this->narrate($eval, $decision, $narrative);

        return $decision;
    }

    /**
     * Por qué salió esta decisión: fuente ganadora, cálculo de revisión
     * humana, guard de contradicción de media, piso crítico, prioridad y
     * política de escalación. Nunca el `decision_reason` ni nombres de reglas,
     * desenlaces o políticas (texto libre del tenant).
     *
     * @param  array{applied: array<string, mixed>, resolved: array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}, review_by_resolver: bool, review_by_outcome: bool, mapped_priority: DecisionPriority, critical_bump: bool, policy: ?EscalationPolicy, trace_steps_count: int}  $narrative
     */
    private function narrate(AIEventEvaluation $eval, Decision $decision, array $narrative): void
    {
        $resolved = $narrative['resolved'];
        $explain = $resolved['explain'];

        if (($explain['guard_check'] ?? null) === 'forced_review') {
            SystemLog::ok('decisions.outcome.forced_human_review', input: [
                'ai_evaluation_id' => $eval->id,
            ], calc: [
                'from_code' => LoggableCode::guard($explain['guard_from_code'] ?? null),
                'latest_media_result' => 'contradicts_event',
                'prior_actionable_decision' => true,
            ], result: [
                'decision_id' => $decision->id,
                'decision_code' => $decision->decision_code,
            ]);
        }

        if (($explain['floor_check'] ?? null) === 'floored') {
            SystemLog::ok('decisions.outcome.floored', input: [
                'ai_evaluation_id' => $eval->id,
            ], calc: [
                'from_code' => LoggableCode::guard($explain['floor_from_code'] ?? null),
                'to_code' => DecisionOutcomeCode::Incident->value,
                'floor_severity_codes' => ResolveDecisionOutcome::CRITICAL_SEVERITY_FLOOR_CODES,
            ], result: [
                'decision_id' => $decision->id,
            ]);
        }

        $calc = $explain;
        unset($calc['guard_from_code'], $calc['floor_from_code']);

        SystemLog::ok('decisions.outcome.resolved', input: [
            'ai_evaluation_id' => $eval->id,
            'ruleset_id' => $decision->ruleset_id,
            'classification' => $eval->classification->value,
        ], calc: [
            ...$calc,
            'review_by_resolver' => $narrative['review_by_resolver'],
            'review_by_outcome' => $narrative['review_by_outcome'],
        ], result: [
            'decision_id' => $decision->id,
            'decision_code' => LoggableCode::guard($decision->decision_code),
            'source_type' => $resolved['sourceType']->value,
            'rule_id' => $resolved['sourceRule']?->id,
            'rule_code' => LoggableCode::guard($resolved['sourceRule']?->code),
            'requires_human_review' => $decision->requires_human_review,
            'trace_steps_count' => $narrative['trace_steps_count'],
        ]);

        SystemLog::ok('decisions.priority.resolved', input: [
            'decision_id' => $decision->id,
        ], calc: [
            'ai_priority_level' => $eval->priority_level->value,
            'requires_human_review' => $decision->requires_human_review,
            'mapped' => $narrative['mapped_priority']->value,
            'critical_bump' => $narrative['critical_bump'],
        ], result: [
            'priority_level' => $decision->priority_level->value,
        ]);

        $this->narrateEscalation($decision, $resolved['sourceRule']?->escalation_policy_id, $narrative['policy']);
    }

    private function narrateEscalation(Decision $decision, ?int $rulePolicyId, ?EscalationPolicy $policy): void
    {
        $rulePolicy = $rulePolicyId === null ? null : $this->describeRulePolicy($decision, $rulePolicyId);

        if ($policy !== null) {
            $ruleUsed = $rulePolicyId === $policy->id;

            SystemLog::ok('decisions.escalation_policy.resolved', input: [
                'decision_id' => $decision->id,
            ], calc: [
                'policy_source' => $ruleUsed ? 'source_rule' : 'team_default_for_escalate',
                'rule_policy_used' => $ruleUsed,
                ...($rulePolicy ?? ['rule_policy_present' => false]),
            ], result: [
                'escalation_policy_id' => $policy->id,
            ]);

            return;
        }

        if ($decision->decision_code === DecisionOutcomeCode::Escalate->value) {
            // Un ESCALATE sin política activa no escala a nadie.
            SystemLog::degraded('decisions.escalation_policy.resolved', reason: 'no_active_team_policy', input: [
                'decision_id' => $decision->id,
            ], calc: $rulePolicy !== null ? [
                'rule_policy_present' => true,
                'rule_policy_scope' => $rulePolicy['rule_policy_scope'],
            ] : null);

            return;
        }

        if ($rulePolicy !== null) {
            SystemLog::degraded(
                'decisions.escalation_policy.resolved',
                reason: $rulePolicy['rule_policy_scope'] === 'own' ? 'rule_policy_inactive' : 'rule_policy_foreign',
                input: ['decision_id' => $decision->id],
                calc: $rulePolicy,
            );

            return;
        }

        SystemLog::skipped('decisions.escalation_policy.resolved', reason: 'not_required', input: [
            'decision_id' => $decision->id,
        ], debug: true);
    }

    /**
     * La política de la regla fuente, sin filtrar nunca un id ajeno: una regla
     * global puede apuntar a la política de otro team, y ese id no sale del
     * tenant del evento. Lectura después del commit, filtrada por el team.
     *
     * @return array{rule_policy_present: true, rule_policy_scope: 'own'|'foreign', rule_policy_id?: int}
     */
    private function describeRulePolicy(Decision $decision, int $rulePolicyId): array
    {
        $own = EscalationPolicy::query()
            ->where('id', $rulePolicyId)
            ->where('team_id', $decision->team_id)
            ->exists();

        return $own
            ? ['rule_policy_present' => true, 'rule_policy_scope' => 'own', 'rule_policy_id' => $rulePolicyId]
            : ['rule_policy_present' => true, 'rule_policy_scope' => 'foreign'];
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
