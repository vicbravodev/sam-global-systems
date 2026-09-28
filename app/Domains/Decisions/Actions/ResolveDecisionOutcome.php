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
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Collection;

class ResolveDecisionOutcome
{
    /**
     * Códigos de `event_severities` cuyo evento nunca puede quedar por debajo
     * de INCIDENT (pánico, colisión, vuelco, manipulación del equipo...).
     *
     * @var list<string>
     */
    public const CRITICAL_SEVERITY_FLOOR_CODES = ['critical'];

    public function __construct(
        private readonly TenantDecisionRulesResolver $rulesResolver,
    ) {}

    /**
     * @param  Collection<int, DecisionRule>  $matchedRules
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}
     */
    public function execute(AIEventEvaluation $eval, Collection $matchedRules): array
    {
        $resolved = $this->guardMediaContradiction($eval, $this->resolve($eval, $matchedRules));

        // El piso de seguridad va AL FINAL: ninguna regla (ni stop_processing),
        // override de tenant, confianza baja ni fallback del agente puede
        // dejar un evento crítico por debajo de INCIDENT.
        return $this->applyCriticalSeverityFloor($eval, $resolved);
    }

    /**
     * ¿El evento de esta evaluación tiene una severidad sujeta al piso de
     * seguridad? Lo usa también EvaluateDecisionRules para la prioridad.
     */
    public function isCriticalSeverity(AIEventEvaluation $eval): bool
    {
        $severityCode = NormalizedEvent::query()
            ->with('eventSeverity')
            ->find($eval->normalized_event_id)
            ?->eventSeverity
            ?->code;

        return $severityCode !== null
            && in_array($severityCode, self::CRITICAL_SEVERITY_FLOOR_CODES, true);
    }

    /**
     * IGNORE, LOG_ONLY, ALERT y REQUIRE_HUMAN_REVIEW suben a INCIDENT; ESCALATE
     * se queda. Única excepción: REQUIRE_HUMAN_REVIEW elegido por una regla
     * configurada (p. ej. la regla opt-in de falsa alarma de pánico resuelta
     * en base, roadmap B6-P7), que ya garantiza que un humano lo revise. Una
     * revisión humana que sale de la IA (unclear, confianza baja, fallo del
     * agente) o del guard de contradicción de media sí sube a INCIDENT.
     *
     * @param  array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}  $resolved
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool}
     */
    private function applyCriticalSeverityFloor(AIEventEvaluation $eval, array $resolved): array
    {
        $code = DecisionOutcomeCode::tryFrom((string) $resolved['outcome']->code);

        if ($code !== null && $code->createsIncident()) {
            return $resolved;
        }

        $ruleChoseReview = $code === DecisionOutcomeCode::RequireHumanReview
            && $resolved['sourceRule'] !== null
            && in_array($resolved['sourceType'], [DecisionSourceType::Rule, DecisionSourceType::TenantPolicy], true);

        if ($ruleChoseReview || ! $this->isCriticalSeverity($eval)) {
            return $resolved;
        }

        $incident = DecisionOutcome::firstOrCreate(
            ['code' => DecisionOutcomeCode::Incident->value],
            ['name' => 'Incident', 'is_terminal' => false],
        );

        return [
            'outcome' => $incident,
            'sourceType' => DecisionSourceType::Fallback,
            // La regla original eligió un desenlace sin incidente: su política
            // de escalación no aplica al piso.
            'sourceRule' => null,
            'reason' => 'Piso de seguridad: evento de severidad crítica elevado de '
                .$resolved['outcome']->code.' a '.DecisionOutcomeCode::Incident->value
                .'. Motivo original: '.$resolved['reason'],
            'requiresHumanReview' => $resolved['requiresHumanReview'],
        ];
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
                'reason' => 'Regla de seguridad obligatoria aplicada: '.$this->ruleLabel($hardSafetyRule).'.',
                'requiresHumanReview' => $requiresHumanReview,
            ];
        }

        $tenantRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->team_id !== null && $rule->outcome_override !== null);

        if ($tenantRule !== null && $tenantRule->outcomeOverride) {
            return [
                'outcome' => $tenantRule->outcomeOverride,
                'sourceType' => DecisionSourceType::TenantPolicy,
                'sourceRule' => $tenantRule,
                'reason' => 'Regla de la empresa aplicada: '.$this->ruleLabel($tenantRule).'.',
                'requiresHumanReview' => $requiresHumanReview,
            ];
        }

        $globalRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->outcome_override !== null);

        if ($globalRule !== null && $globalRule->outcomeOverride) {
            return [
                'outcome' => $globalRule->outcomeOverride,
                'sourceType' => DecisionSourceType::Rule,
                'sourceRule' => $globalRule,
                'reason' => 'Regla aplicada: '.$this->ruleLabel($globalRule).'.',
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
                'reason' => 'Decisión según la clasificación de la IA: '.mb_strtolower($eval->classification->label()).'.',
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
            'reason' => 'Ninguna regla aplicó; el evento solo se registra.',
            'requiresHumanReview' => $requiresHumanReview,
        ];
    }

    /**
     * Nombre legible de la regla para el operador, con su código para trazabilidad.
     */
    private function ruleLabel(DecisionRule $rule): string
    {
        $name = trim((string) $rule->name);

        return $name !== '' ? '«'.$name.'» ('.$rule->code.')' : (string) $rule->code;
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

        $hadActionableDecision = Decision::query()
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
            'reason' => 'Las imágenes contradicen el evento, pero una decisión previa ya actuó sobre él: '
                .'se bloquea la baja a '.$resolved['outcome']->code.' hasta que un operador lo revise.',
            'requiresHumanReview' => true,
        ];
    }

    private function latestMediaAssessmentResult(AIEventEvaluation $eval): ?MediaAssessmentResult
    {
        $evaluationIds = AIEventEvaluation::query()
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
