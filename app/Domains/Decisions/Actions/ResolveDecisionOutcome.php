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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    /** Riesgo desde el que un evento real sin regla escala (mapeo de la IA). */
    private const float ESCALATE_RISK_THRESHOLD = 0.85;

    /** Riesgo desde el que un evento real sin regla abre incidente (mapeo de la IA). */
    private const float INCIDENT_RISK_THRESHOLD = 0.6;

    public function __construct(
        private readonly TenantDecisionRulesResolver $rulesResolver,
    ) {}

    /**
     * @param  Collection<int, DecisionRule>  $matchedRules
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}
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
     * @param  array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}  $resolved
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}
     */
    private function applyCriticalSeverityFloor(AIEventEvaluation $eval, array $resolved): array
    {
        $code = DecisionOutcomeCode::tryFrom($resolved['outcome']->code);

        if ($code !== null && $code->createsIncident()) {
            $resolved['explain']['floor_check'] = 'outcome_creates_incident';

            return $resolved;
        }

        $ruleChoseReview = $code === DecisionOutcomeCode::RequireHumanReview
            && $resolved['sourceRule'] !== null
            && in_array($resolved['sourceType'], [DecisionSourceType::Rule, DecisionSourceType::TenantPolicy], true);

        if ($ruleChoseReview) {
            $resolved['explain']['floor_check'] = 'rule_chose_review';

            return $resolved;
        }

        if (! $this->isCriticalSeverity($eval)) {
            $resolved['explain']['floor_check'] = 'not_critical';

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
            'explain' => [
                ...$resolved['explain'],
                'floor_check' => 'floored',
                'floor_from_code' => $resolved['outcome']->code,
            ],
        ];
    }

    /**
     * @param  Collection<int, DecisionRule>  $matchedRules
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}
     */
    private function resolve(AIEventEvaluation $eval, Collection $matchedRules): array
    {
        // One query for every matched rule's outcome instead of one per rule.
        // (The matched set arrives as a plain collection of rule models.)
        EloquentCollection::make($matchedRules->all())->loadMissing('outcomeOverride');

        $policy = $this->rulesResolver->resolve($eval->team_id);

        $confidence = $eval->confidence_score ?? 0.0;
        $requiresHumanReview = $confidence < $policy->humanReviewConfidenceThreshold;

        $explain = [
            'confidence' => $confidence,
            'human_review_threshold' => $policy->humanReviewConfidenceThreshold,
            'review_by_confidence' => $requiresHumanReview,
            'risk' => $eval->risk_score ?? 0.0,
        ];

        $hardSafetyRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->stop_processing && $rule->outcome_override !== null);

        if ($hardSafetyRule !== null && $hardSafetyRule->outcomeOverride !== null) {
            return [
                'outcome' => $hardSafetyRule->outcomeOverride,
                'sourceType' => DecisionSourceType::Rule,
                'sourceRule' => $hardSafetyRule,
                'reason' => 'Regla de seguridad obligatoria aplicada: '.$this->ruleLabel($hardSafetyRule).'.'
                    .$this->aiReadingOfForcedOutcome($eval),
                'requiresHumanReview' => $requiresHumanReview,
                'explain' => [...$explain, 'source' => 'hard_safety', 'ai_classification' => $eval->classification?->value],
            ];
        }

        $tenantRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->team_id !== null && $rule->outcome_override !== null);

        if ($tenantRule !== null && $tenantRule->outcomeOverride !== null) {
            return [
                'outcome' => $tenantRule->outcomeOverride,
                'sourceType' => DecisionSourceType::TenantPolicy,
                'sourceRule' => $tenantRule,
                'reason' => 'Regla de la empresa aplicada: '.$this->ruleLabel($tenantRule).'.',
                'requiresHumanReview' => $requiresHumanReview,
                'explain' => [...$explain, 'source' => 'tenant_rule'],
            ];
        }

        $globalRule = $matchedRules->first(fn (DecisionRule $rule) => $rule->outcome_override !== null);

        if ($globalRule !== null && $globalRule->outcomeOverride !== null) {
            return [
                'outcome' => $globalRule->outcomeOverride,
                'sourceType' => DecisionSourceType::Rule,
                'sourceRule' => $globalRule,
                'reason' => 'Regla aplicada: '.$this->ruleLabel($globalRule).'.',
                'requiresHumanReview' => $requiresHumanReview,
                'explain' => [...$explain, 'source' => 'global_rule'],
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
                'explain' => [
                    ...$explain,
                    'source' => 'ai_mapping',
                    'ai_outcome_code' => $aiOutcomeCode->value,
                    'ai_mapping_thresholds' => [
                        'escalate' => self::ESCALATE_RISK_THRESHOLD,
                        'incident' => self::INCIDENT_RISK_THRESHOLD,
                    ],
                ],
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
            'explain' => [
                ...$explain,
                'source' => 'log_only_fallback',
                'ai_outcome_code' => $aiOutcomeCode->value,
                'ai_outcome_missing' => true,
            ],
        ];
    }

    /**
     * Una regla obligatoria (p. ej. «Botón de pánico → incidente») fija el
     * desenlace sin importar lo que opine la IA; sin esta frase el operador ve
     * la misma razón en la v1 («evento real») y en la v2 que, tras mirar las
     * cámaras, lo juzga falsa alarma — y no sabe si la IA cambió de opinión.
     */
    private function aiReadingOfForcedOutcome(AIEventEvaluation $eval): string
    {
        $classification = $eval->classification;

        $confidence = $eval->confidence_score !== null
            ? ' ('.(int) round($eval->confidence_score * 100).' %)'
            : '';

        return match ($classification) {
            EventClassification::RealEvent => " La IA también lo considera un evento real{$confidence}.",
            EventClassification::FalsePositive, EventClassification::Noise, EventClassification::Duplicate => ' La IA lo considera '
                .mb_strtolower($classification->label()).$confidence
                .', pero la regla no permite descartarlo sola: un operador debe confirmarlo antes de cerrar.',
            default => ' La IA aún no puede confirmarlo: '.mb_strtolower($classification->label()).$confidence.'.',
        };
    }

    /**
     * Nombre legible de la regla para el operador, con su código para trazabilidad.
     */
    private function ruleLabel(DecisionRule $rule): string
    {
        $name = trim($rule->name);

        return $name !== '' ? '«'.$name.'» ('.$rule->code.')' : $rule->code;
    }

    /**
     * Footage that contradicts an event the engine already acted on must land
     * in front of a human instead of silently degrading the decision: a prior
     * actionable decision usually means an open incident, and an IGNORE /
     * LOG_ONLY re-decision would orphan it. The incident is never auto-closed.
     *
     * @param  array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}  $resolved
     * @return array{outcome: DecisionOutcome, sourceType: DecisionSourceType, sourceRule: ?DecisionRule, reason: string, requiresHumanReview: bool, explain: array<string, mixed>}
     */
    private function guardMediaContradiction(AIEventEvaluation $eval, array $resolved): array
    {
        $code = DecisionOutcomeCode::tryFrom($resolved['outcome']->code);

        if ($code === null || ! $code->isTerminal()) {
            $resolved['explain']['guard_check'] = 'outcome_not_terminal';

            return $resolved;
        }

        $latest = $this->latestMediaAssessmentResult($eval);

        if ($latest !== MediaAssessmentResult::ContradictsEvent) {
            $resolved['explain']['guard_check'] = 'media_not_contradicting';
            $resolved['explain']['latest_media_result'] = $latest?->value;

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
            $resolved['explain']['guard_check'] = 'no_prior_actionable_decision';
            $resolved['explain']['latest_media_result'] = $latest->value;

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
            'explain' => [
                ...$resolved['explain'],
                'guard_check' => 'forced_review',
                'guard_from_code' => $resolved['outcome']->code,
                'latest_media_result' => MediaAssessmentResult::ContradictsEvent->value,
            ],
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

        $risk = $eval->risk_score ?? 0.0;

        return match ($eval->classification) {
            EventClassification::RealEvent => $risk >= self::ESCALATE_RISK_THRESHOLD
                ? DecisionOutcomeCode::Escalate
                : ($risk >= self::INCIDENT_RISK_THRESHOLD ? DecisionOutcomeCode::Incident : DecisionOutcomeCode::Alert),
            EventClassification::Unclear => DecisionOutcomeCode::RequireHumanReview,
            EventClassification::PendingEvidence => DecisionOutcomeCode::RequireHumanReview,
            EventClassification::FalsePositive, EventClassification::Duplicate => DecisionOutcomeCode::Ignore,
            EventClassification::Noise => DecisionOutcomeCode::LogOnly,
        };
    }
}
