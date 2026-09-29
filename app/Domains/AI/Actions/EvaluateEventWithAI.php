<?php

namespace App\Domains\AI\Actions;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Data\TenantAIProfileData;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\InferenceStatus;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Models\AIDecisionSignal;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIExplanation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Support\HeuristicRulesRunner;
use App\Domains\AI\Support\MediaVerdictFusion;
use App\Domains\AI\Support\TenantAIQuota;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;
use Throwable;

class EvaluateEventWithAI
{
    /**
     * Umbrales de riesgo (inclusivos) de la prioridad de un evento accionable.
     */
    private const array PRIORITY_THRESHOLDS = ['urgent' => 0.85, 'high' => 0.6, 'normal' => 0.3];

    /**
     * Límites (inclusivos) del clamp que aplica la fusión del veredicto visual.
     */
    private const array CONFIDENCE_BOUNDS = [0.05, 0.99];

    private const array RISK_BOUNDS = [0.0, 1.0];

    public function __construct(
        private readonly ResolveTenantAIProfile $resolveTenantProfile,
        private readonly BuildAIInputContext $buildInputContext,
        private readonly CalculateRiskScore $calculateRiskScore,
        private readonly GenerateRecommendedActions $generateRecommendedActions,
        private readonly DetectFalsePositive $detectFalsePositive,
        private readonly HeuristicRulesRunner $rulesRunner,
        private readonly MediaVerdictFusion $mediaVerdictFusion,
        private readonly EventEvaluationAgent $agent,
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

    /**
     * @param  array<string, mixed>|null  $operatorFeedback  Feedback humano (veredictos del operador y motivos del diálogo "Feedback") que debe llegar al modelo; lo arma `OperatorFeedbackCollector`.
     */
    public function execute(NormalizedEvent $event, ?int $version = null, ?array $operatorFeedback = null): AIEventEvaluation
    {
        $snapshot = EventContextSnapshot::query()
            ->where('normalized_event_id', $event->id)
            ->first();

        $profile = $this->resolveTenantProfile->execute($event->team_id);
        $input = $this->buildInputContext->execute($event, $snapshot, $profile);
        $input = $this->withOperatorFeedback($input, $operatorFeedback);
        $riskScore = $this->calculateRiskScore->execute($event, $snapshot);

        // Veredicto visual del evento (si ya hay media evaluada): ajusta de
        // forma determinista confianza/riesgo/explicación en todas las rutas
        // no deterministas. En la primera evaluación aún no hay assessments.
        // Se calcula por ruta porque la dirección del ajuste depende de la
        // clasificación final (falso positivo vs evento real).
        $isCriticalEvent = MediaVerdictFusion::isCriticalEvent($event);
        $explainMedia = fn (EventClassification $classification): array => $this->mediaVerdictFusion->explain(
            $input->mediaAssessments,
            $classification,
            $isCriticalEvent,
        );

        $rulesDecision = $this->rulesRunner->evaluate($event, $snapshot?->signals_json ?? []);

        $version ??= $this->nextVersion($event->id);
        $operatorFeedbackPresent = $operatorFeedback !== null && $operatorFeedback !== [];

        if ($rulesDecision !== null) {
            $evaluation = DB::transaction(function () use ($event, $version, $rulesDecision, $riskScore, $input) {
                return $this->persistEvaluation(
                    event: $event,
                    version: $version,
                    mode: $rulesDecision['mode'],
                    classification: $rulesDecision['classification'],
                    confidence: 0.95,
                    riskScore: $riskScore,
                    explanationSummary: 'Resuelto sin IA por una regla automática: '.$this->describeRuleReason($rulesDecision['reason']).'.',
                    reasoningSteps: ['Regla automática: '.$this->describeRuleReason($rulesDecision['reason']).'.'],
                    keyFactors: ['rule_reason' => $rulesDecision['reason']],
                    modelUsed: 'rules_engine:1.0',
                    agentInputSnapshot: $input,
                    inferenceTokens: null,
                    inferenceLatencyMs: null,
                    inferenceCostEstimate: null,
                    inferenceStatus: InferenceStatus::Success,
                );
            });

            $this->narrate(
                $evaluation,
                route: 'heuristic',
                baseRisk: $riskScore,
                agentRiskDelta: null,
                riskAfterAgent: $riskScore,
                baseConfidence: 0.95,
                explain: null,
                isCriticalEvent: $isCriticalEvent,
                result: null,
                operatorFeedbackPresent: $operatorFeedbackPresent,
                heuristicRule: explode(':', $rulesDecision['reason'], 2)[0],
            );

            return $evaluation;
        }

        if ($this->quotaExceeded($event, $profile)) {
            // explain() es puro (solo arrays): calcularlo fuera de la transacción
            // da el mismo resultado y deja el delta disponible para la narrativa.
            $explain = $explainMedia(EventClassification::Unclear);
            $fusion = $explain['fusion'];

            $evaluation = DB::transaction(function () use ($event, $version, $riskScore, $input, $fusion) {
                $fused = $this->applyFusion(
                    $fusion,
                    confidence: 0.5,
                    riskScore: $riskScore,
                    explanation: 'Cuota de IA del tenant agotada; se evalúa solo con reglas.',
                    reasoningSteps: ['quota_exceeded'],
                    keyFactors: ['reason' => 'quota_exceeded'],
                );

                return $this->persistEvaluation(
                    event: $event,
                    version: $version,
                    mode: EvaluationMode::RulesOnly,
                    classification: EventClassification::Unclear,
                    confidence: $fused['confidence'],
                    riskScore: $fused['riskScore'],
                    explanationSummary: $fused['explanation'],
                    reasoningSteps: $fused['reasoningSteps'],
                    keyFactors: $fused['keyFactors'],
                    modelUsed: 'rules_engine:1.0',
                    agentInputSnapshot: $input,
                    inferenceTokens: null,
                    inferenceLatencyMs: null,
                    inferenceCostEstimate: null,
                    inferenceStatus: InferenceStatus::Success,
                );
            });

            $this->narrate(
                $evaluation,
                route: 'quota_exceeded',
                baseRisk: $riskScore,
                agentRiskDelta: null,
                riskAfterAgent: $riskScore,
                baseConfidence: 0.5,
                explain: $explain,
                isCriticalEvent: $isCriticalEvent,
                result: null,
                operatorFeedbackPresent: $operatorFeedbackPresent,
                heuristicRule: null,
            );

            return $evaluation;
        }

        try {
            $result = $this->agent->evaluate($input);
        } catch (Throwable $exception) {
            // Solo afirma que el agente falló; el fallback a reglas se narra
            // después del commit (ai.evaluation.rules_only).
            SystemLog::degraded('ai.evaluation.agent_failed', reason: 'agent_error', input: ['normalized_event_id' => $event->id], error: $exception);

            $explain = $explainMedia(EventClassification::Unclear);
            $fusion = $explain['fusion'];

            $evaluation = DB::transaction(function () use ($event, $version, $riskScore, $input, $exception, $fusion) {
                $fused = $this->applyFusion(
                    $fusion,
                    confidence: 0.4,
                    riskScore: $riskScore,
                    // El mensaje crudo de la excepción (URLs, cuerpos del
                    // proveedor, claves) se queda en el log; al operador le
                    // llega un texto genérico y en key_factors solo la clase.
                    explanation: 'El análisis de IA no estuvo disponible; se evalúa solo con reglas.',
                    reasoningSteps: ['agent_error_fallback'],
                    keyFactors: ['error_class' => class_basename($exception)],
                );

                return $this->persistEvaluation(
                    event: $event,
                    version: $version,
                    mode: EvaluationMode::RulesOnly,
                    classification: EventClassification::Unclear,
                    confidence: $fused['confidence'],
                    riskScore: $fused['riskScore'],
                    explanationSummary: $fused['explanation'],
                    reasoningSteps: $fused['reasoningSteps'],
                    keyFactors: $fused['keyFactors'],
                    modelUsed: 'rules_engine:1.0',
                    agentInputSnapshot: $input,
                    inferenceTokens: null,
                    inferenceLatencyMs: null,
                    inferenceCostEstimate: null,
                    inferenceStatus: InferenceStatus::Error,
                );
            });

            $this->narrate(
                $evaluation,
                route: 'agent_error',
                baseRisk: $riskScore,
                agentRiskDelta: null,
                riskAfterAgent: $riskScore,
                baseConfidence: 0.4,
                explain: $explain,
                isCriticalEvent: $isCriticalEvent,
                result: null,
                operatorFeedbackPresent: $operatorFeedbackPresent,
                heuristicRule: null,
            );

            return $evaluation;
        }

        $finalRiskScore = round(max(0.0, min(1.0, $riskScore + $result->riskScoreDelta)), 2);

        $explain = $explainMedia($result->classification);
        $fusion = $explain['fusion'];

        $evaluation = DB::transaction(function () use ($event, $version, $result, $finalRiskScore, $input, $fusion) {
            $fused = $this->applyFusion(
                $fusion,
                confidence: $result->confidenceScore,
                riskScore: $finalRiskScore,
                explanation: $result->explanationSummary,
                reasoningSteps: $result->reasoningSteps,
                keyFactors: $result->keyFactors,
            );

            $evaluation = $this->persistEvaluation(
                event: $event,
                version: $version,
                // Con veredicto visual fusionado la evaluación ya no es solo
                // de texto: queda marcada como híbrida.
                mode: $fusion !== null ? EvaluationMode::Hybrid : EvaluationMode::AiText,
                classification: $result->classification,
                confidence: $fused['confidence'],
                riskScore: $fused['riskScore'],
                explanationSummary: $fused['explanation'],
                reasoningSteps: $fused['reasoningSteps'],
                keyFactors: $fused['keyFactors'],
                modelUsed: $result->modelUsed,
                agentInputSnapshot: $input,
                inferenceTokens: $result->totalTokens(),
                inferenceInputTokens: $result->inputTokens,
                inferenceOutputTokens: $result->outputTokens,
                inferenceLatencyMs: $result->latencyMs,
                inferenceCostEstimate: $result->costEstimate,
                inferenceStatus: InferenceStatus::Success,
            );

            $this->recordAgentUsage($evaluation, $result->inputTokens, $result->outputTokens);

            return $evaluation;
        });

        $this->narrate(
            $evaluation,
            route: 'ai',
            baseRisk: $riskScore,
            agentRiskDelta: $result->riskScoreDelta,
            riskAfterAgent: $finalRiskScore,
            baseConfidence: $result->confidenceScore,
            explain: $explain,
            isCriticalEvent: $isCriticalEvent,
            result: $result,
            operatorFeedbackPresent: $operatorFeedbackPresent,
            heuristicRule: null,
        );

        return $evaluation;
    }

    /**
     * Narrativa post-commit de la evaluación: se llama después de que
     * `DB::transaction()` devolvió, así nada afirma una evaluación que un
     * rollback pudo deshacer. Nunca registra explicación, key_factors (salvo
     * la clase de error ya persistida), feedback del operador ni prompt.
     *
     * @param  array{branch: string, fusion: array{step: string, sentence: string, confidenceDelta: float, riskDelta: float, keyFactors: array<string, int>}|null, assessed: int, confirms: int, contradicts: int, visible_threats: int, dismissive: bool}|null  $explain  Null en la ruta heurística (no hay fusión).
     */
    private function narrate(
        AIEventEvaluation $evaluation,
        string $route,
        float $baseRisk,
        ?float $agentRiskDelta,
        float $riskAfterAgent,
        float $baseConfidence,
        ?array $explain,
        bool $isCriticalEvent,
        ?AIEvaluationResult $result,
        bool $operatorFeedbackPresent,
        ?string $heuristicRule,
    ): void {
        if ($route !== 'ai') {
            $errorClass = $route === 'agent_error'
                ? ($evaluation->signals_json['key_factors']['error_class'] ?? null)
                : null;

            $rulesOnlyInput = ['normalized_event_id' => $evaluation->normalized_event_id];
            $rulesOnlyResult = [
                'evaluation_id' => $evaluation->id,
                'heuristic_rule' => $heuristicRule,
                'error_class' => is_string($errorClass) ? $errorClass : null,
            ];

            if ($route === 'heuristic') {
                SystemLog::skipped('ai.evaluation.rules_only', reason: 'heuristic_short_circuit', input: $rulesOnlyInput, result: $rulesOnlyResult);
            } else {
                SystemLog::degraded('ai.evaluation.rules_only', reason: $route, input: $rulesOnlyInput, result: $rulesOnlyResult);
            }
        }

        $fusion = $explain['fusion'] ?? null;

        if ($explain !== null) {
            if ($fusion === null) {
                SystemLog::skipped(
                    'ai.media_fusion.skipped',
                    reason: $explain['branch'],
                    input: ['evaluation_id' => $evaluation->id],
                    calc: ['media_assessed_count' => $explain['assessed']],
                    debug: $explain['branch'] === 'no_media',
                );
            } else {
                SystemLog::ok(
                    'ai.media_fusion.applied',
                    input: [
                        'evaluation_id' => $evaluation->id,
                        'classification' => $evaluation->classification->value,
                        'is_critical_event' => $isCriticalEvent,
                    ],
                    calc: [
                        'branch' => $explain['branch'],
                        'media_assessed_count' => $explain['assessed'],
                        'media_confirms_count' => $explain['confirms'],
                        'media_contradicts_count' => $explain['contradicts'],
                        'media_visible_threat_count' => $explain['visible_threats'],
                        'dismissive' => $explain['dismissive'],
                        'confidence_before' => $baseConfidence,
                        'confidence_delta' => $fusion['confidenceDelta'],
                        'confidence_bounds' => self::CONFIDENCE_BOUNDS,
                        'confidence_after' => $evaluation->confidence_score,
                        'risk_before' => $riskAfterAgent,
                        'risk_delta' => $fusion['riskDelta'],
                        'risk_bounds' => self::RISK_BOUNDS,
                        'risk_after' => $evaluation->risk_score,
                    ],
                );
            }
        }

        SystemLog::ok(
            'ai.priority.resolved',
            input: ['evaluation_id' => $evaluation->id],
            calc: [
                'risk_score' => $evaluation->risk_score,
                'classification' => $evaluation->classification->value,
                'actionable' => $evaluation->classification->isActionable(),
                'thresholds' => self::PRIORITY_THRESHOLDS,
            ],
            result: [
                'priority_level' => $evaluation->priority_level->value,
                'requires_action' => $evaluation->requires_action,
            ],
        );

        SystemLog::ok(
            'ai.false_positive.checked',
            input: ['evaluation_id' => $evaluation->id],
            calc: [
                'classification' => $evaluation->classification->value,
                'confidence' => $evaluation->confidence_score,
                'threshold' => DetectFalsePositive::HIGH_CONFIDENCE_THRESHOLD,
            ],
            result: ['is_false_positive' => $this->detectFalsePositive->isFalsePositive($evaluation)],
        );

        SystemLog::ok(
            'ai.evaluation.completed',
            input: [
                'normalized_event_id' => $evaluation->normalized_event_id,
                'evaluation_version' => $evaluation->evaluation_version,
                'route' => $route,
                'operator_feedback_present' => $operatorFeedbackPresent,
            ],
            calc: [
                'base_risk' => $baseRisk,
                'agent_risk_delta' => $agentRiskDelta,
                'risk_after_agent' => $riskAfterAgent,
                'fusion_applied' => $fusion !== null,
                'fusion_risk_delta' => $fusion['riskDelta'] ?? 0.0,
                // Sin fusión este paso no recorta nada (solo se redondea al
                // persistir): el clamp se registra solo cuando se aplicó.
                'risk_clamp' => $fusion !== null ? self::RISK_BOUNDS : null,
                'risk_score' => $evaluation->risk_score,
                'base_confidence' => $baseConfidence,
                'fusion_confidence_delta' => $fusion['confidenceDelta'] ?? 0.0,
                'confidence_clamp' => $fusion !== null ? self::CONFIDENCE_BOUNDS : null,
                'confidence' => $evaluation->confidence_score,
            ],
            result: [
                'evaluation_id' => $evaluation->id,
                'mode' => $evaluation->evaluation_mode->value,
                'classification' => $evaluation->classification->value,
                'priority_level' => $evaluation->priority_level->value,
                'model' => $evaluation->model_used,
                'input_tokens' => $result?->inputTokens,
                'output_tokens' => $result?->outputTokens,
                'cost_estimate' => $result?->costEstimate,
                'latency_ms' => $result?->latencyMs,
                'ai_inference_log_id' => AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->value('id'),
            ],
        );
    }

    /**
     * Inyecta el feedback del operador en el input del agente bajo
     * `recent_history.operator_feedback`, sin tocar el DTO: se reconstruye con
     * todas sus propiedades públicas (incluidas las que se añadan en el
     * futuro) y sólo se amplía `recentHistory`. Así viaja en el payload JSON
     * que ve el modelo y queda en el snapshot del inference log.
     *
     * @param  array<string, mixed>|null  $operatorFeedback
     */
    private function withOperatorFeedback(AIInputContext $input, ?array $operatorFeedback): AIInputContext
    {
        if ($operatorFeedback === null || $operatorFeedback === []) {
            return $input;
        }

        $properties = get_object_vars($input);
        $properties['recentHistory'] = [
            ...$input->recentHistory,
            'operator_feedback' => $operatorFeedback,
        ];

        return new AIInputContext(...$properties);
    }

    /**
     * Texto en español para el operador a partir del código interno de la
     * regla determinista (el código crudo se conserva en key_factors).
     */
    private function describeRuleReason(string $reason): string
    {
        return match (true) {
            str_starts_with($reason, 'known_noise_signature:') => 'señal de ruido conocida ('.substr($reason, strlen('known_noise_signature:')).')',
            $reason === 'recent_duplicates_in_window' => 'el mismo evento se repitió varias veces en poco tiempo',
            default => $reason,
        };
    }

    /**
     * Aplica el ajuste del veredicto visual a los valores que se van a
     * persistir. Con $fusion null devuelve los valores intactos.
     *
     * @param  array{step: string, sentence: string, confidenceDelta: float, riskDelta: float, keyFactors: array<string, int>}|null  $fusion
     * @param  array<int, string>  $reasoningSteps
     * @param  array<string, mixed>  $keyFactors
     * @return array{confidence: float, riskScore: float, explanation: string, reasoningSteps: array<int, string>, keyFactors: array<string, mixed>}
     */
    private function applyFusion(
        ?array $fusion,
        float $confidence,
        float $riskScore,
        string $explanation,
        array $reasoningSteps,
        array $keyFactors,
    ): array {
        if ($fusion === null) {
            return [
                'confidence' => $confidence,
                'riskScore' => $riskScore,
                'explanation' => $explanation,
                'reasoningSteps' => $reasoningSteps,
                'keyFactors' => $keyFactors,
            ];
        }

        return [
            'confidence' => round(max(self::CONFIDENCE_BOUNDS[0], min(self::CONFIDENCE_BOUNDS[1], $confidence + $fusion['confidenceDelta'])), 2),
            'riskScore' => round(max(self::RISK_BOUNDS[0], min(self::RISK_BOUNDS[1], $riskScore + $fusion['riskDelta'])), 2),
            'explanation' => rtrim($explanation) === ''
                ? $fusion['sentence']
                : rtrim($explanation).' '.$fusion['sentence'],
            'reasoningSteps' => [...$reasoningSteps, $fusion['step']],
            'keyFactors' => [...$keyFactors, ...$fusion['keyFactors']],
        ];
    }

    /**
     * @param  array<int, string>  $reasoningSteps
     * @param  array<string, mixed>  $keyFactors
     */
    private function persistEvaluation(
        NormalizedEvent $event,
        int $version,
        EvaluationMode $mode,
        EventClassification $classification,
        float $confidence,
        float $riskScore,
        string $explanationSummary,
        array $reasoningSteps,
        array $keyFactors,
        string $modelUsed,
        AIInputContext $agentInputSnapshot,
        ?int $inferenceTokens,
        ?int $inferenceLatencyMs,
        ?float $inferenceCostEstimate,
        InferenceStatus $inferenceStatus,
        ?int $inferenceInputTokens = null,
        ?int $inferenceOutputTokens = null,
    ): AIEventEvaluation {
        $priority = $this->priorityFor($classification, $riskScore);

        $evaluation = AIEventEvaluation::create([
            'normalized_event_id' => $event->id,
            'team_id' => $event->team_id,
            'evaluation_version' => $version,
            'evaluation_mode' => $mode,
            'classification' => $classification,
            'confidence_score' => round($confidence, 2),
            'risk_score' => $riskScore,
            'priority_level' => $priority,
            'is_real_event' => $classification === EventClassification::RealEvent ? true
                : ($classification->isActionable() ? null : false),
            'requires_action' => $classification->isActionable() && $priority->score() >= EvaluationPriority::Normal->score(),
            'recommended_action' => null,
            'explanation_text' => $explanationSummary,
            'signals_json' => [
                'reasoning_steps' => $reasoningSteps,
                'key_factors' => $keyFactors,
            ],
            'evidence_summary_json' => [],
            'model_used' => $modelUsed,
            'evaluated_at' => now(),
        ]);

        AIExplanation::create([
            'evaluation_id' => $evaluation->id,
            'summary' => $explanationSummary,
            'reasoning_steps_json' => $reasoningSteps,
            'key_factors_json' => $keyFactors,
            'confidence_breakdown_json' => [
                'final_confidence' => round($confidence, 2),
                'risk_score' => $riskScore,
            ],
            'evidence_used_json' => [],
        ]);

        foreach ($reasoningSteps as $index => $step) {
            AIDecisionSignal::create([
                'evaluation_id' => $evaluation->id,
                'signal_code' => 'reasoning_step_'.$index,
                'signal_value' => (string) $step,
                'weight' => 1.0 / max(count($reasoningSteps), 1),
                'description' => 'Reasoning step captured from pipeline.',
            ]);
        }

        AIInferenceLog::create([
            'evaluation_id' => $evaluation->id,
            'input_snapshot_json' => $agentInputSnapshot->toArray(),
            'output_json' => [
                'classification' => $classification->value,
                'confidence_score' => $confidence,
                'risk_score' => $riskScore,
            ],
            'latency_ms' => $inferenceLatencyMs,
            'tokens_used' => $inferenceTokens,
            'input_tokens' => $inferenceInputTokens,
            'output_tokens' => $inferenceOutputTokens,
            'media_assets_count' => 0,
            'cost_estimate' => $inferenceCostEstimate,
            'status' => $inferenceStatus,
        ]);

        $this->recordCallUsage($evaluation);

        $this->generateRecommendedActions->execute($evaluation);
        $this->detectFalsePositive->execute($evaluation);

        PipelineTrace::add(['ai_evaluation_id' => $evaluation->id]);

        AIEvaluationCompleted::dispatch($evaluation);

        return $evaluation;
    }

    private function priorityFor(EventClassification $classification, float $riskScore): EvaluationPriority
    {
        if (! $classification->isActionable()) {
            return EvaluationPriority::Low;
        }

        return match (true) {
            $riskScore >= self::PRIORITY_THRESHOLDS['urgent'] => EvaluationPriority::Urgent,
            $riskScore >= self::PRIORITY_THRESHOLDS['high'] => EvaluationPriority::High,
            $riskScore >= self::PRIORITY_THRESHOLDS['normal'] => EvaluationPriority::Normal,
            default => EvaluationPriority::Low,
        };
    }

    private function nextVersion(int $normalizedEventId): int
    {
        return (int) AIEventEvaluation::query()
            ->where('normalized_event_id', $normalizedEventId)
            ->max('evaluation_version') + 1;
    }

    /**
     * Monthly-token and daily-call quota; critical-severity events bypass it
     * and always reach the model (see `TenantAIQuota`).
     */
    private function quotaExceeded(NormalizedEvent $event, TenantAIProfileData $profile): bool
    {
        return app(TenantAIQuota::class)->blocks($event, $profile, 'text');
    }

    private function recordCallUsage(AIEventEvaluation $evaluation): void
    {
        if (! UsageMeter::where('code', 'ai_calls')->exists()) {
            return;
        }

        $this->recordUsageEvent->execute(
            teamId: $evaluation->team_id,
            meterCode: 'ai_calls',
            quantity: 1,
            eventKey: 'ai_call:'.$evaluation->id,
            metadata: [
                'normalized_event_id' => $evaluation->normalized_event_id,
                'evaluation_version' => $evaluation->evaluation_version,
            ],
        );
    }

    private function recordAgentUsage(AIEventEvaluation $evaluation, int $inputTokens, int $outputTokens): void
    {
        if ($inputTokens > 0 && UsageMeter::where('code', 'ai_tokens_in')->exists()) {
            $this->recordUsageEvent->execute(
                teamId: $evaluation->team_id,
                meterCode: 'ai_tokens_in',
                quantity: $inputTokens,
                eventKey: 'ai_tokens_in:'.$evaluation->id,
                metadata: ['normalized_event_id' => $evaluation->normalized_event_id],
            );
        }

        if ($outputTokens > 0 && UsageMeter::where('code', 'ai_tokens_out')->exists()) {
            $this->recordUsageEvent->execute(
                teamId: $evaluation->team_id,
                meterCode: 'ai_tokens_out',
                quantity: $outputTokens,
                eventKey: 'ai_tokens_out:'.$evaluation->id,
                metadata: ['normalized_event_id' => $evaluation->normalized_event_id],
            );
        }
    }
}
