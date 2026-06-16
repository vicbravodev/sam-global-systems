<?php

namespace App\Domains\AI\Actions;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Data\AIInputContext;
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
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateEventWithAI
{
    public function __construct(
        private readonly ResolveTenantAIProfile $resolveTenantProfile,
        private readonly BuildAIInputContext $buildInputContext,
        private readonly CalculateRiskScore $calculateRiskScore,
        private readonly GenerateRecommendedActions $generateRecommendedActions,
        private readonly DetectFalsePositive $detectFalsePositive,
        private readonly HeuristicRulesRunner $rulesRunner,
        private readonly EventEvaluationAgent $agent,
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

    public function execute(NormalizedEvent $event, ?int $version = null): AIEventEvaluation
    {
        $snapshot = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        $profile = $this->resolveTenantProfile->execute($event->team_id);
        $input = $this->buildInputContext->execute($event, $snapshot, $profile);
        $riskScore = $this->calculateRiskScore->execute($event, $snapshot);

        $rulesDecision = $this->rulesRunner->evaluate($event, $snapshot?->signals_json ?? []);

        $version ??= $this->nextVersion($event->id);

        if ($rulesDecision !== null) {
            return DB::transaction(function () use ($event, $version, $rulesDecision, $riskScore, $input) {
                return $this->persistEvaluation(
                    event: $event,
                    version: $version,
                    mode: $rulesDecision['mode'],
                    classification: $rulesDecision['classification'],
                    confidence: 0.95,
                    riskScore: $riskScore,
                    explanationSummary: 'Resuelto por regla determinista: '.$rulesDecision['reason'],
                    reasoningSteps: ['rules_match:'.$rulesDecision['reason']],
                    keyFactors: ['rule_reason' => $rulesDecision['reason']],
                    modelUsed: 'rules_engine:1.0',
                    agentInputSnapshot: $input,
                    inferenceTokens: null,
                    inferenceLatencyMs: null,
                    inferenceCostEstimate: null,
                    inferenceStatus: InferenceStatus::Success,
                );
            });
        }

        if ($this->quotaExceeded($event->team_id, $profile->monthlyTokenLimit)) {
            return DB::transaction(function () use ($event, $version, $riskScore, $input) {
                return $this->persistEvaluation(
                    event: $event,
                    version: $version,
                    mode: EvaluationMode::RulesOnly,
                    classification: EventClassification::Unclear,
                    confidence: 0.5,
                    riskScore: $riskScore,
                    explanationSummary: 'Cuota de IA del tenant agotada; se evalúa solo con reglas.',
                    reasoningSteps: ['quota_exceeded'],
                    keyFactors: ['reason' => 'quota_exceeded'],
                    modelUsed: 'rules_engine:1.0',
                    agentInputSnapshot: $input,
                    inferenceTokens: null,
                    inferenceLatencyMs: null,
                    inferenceCostEstimate: null,
                    inferenceStatus: InferenceStatus::Success,
                );
            });
        }

        try {
            $result = $this->agent->evaluate($input);
        } catch (Throwable $exception) {
            Log::warning('EventEvaluationAgent failed; falling back to rules_only', [
                'normalized_event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

            return DB::transaction(function () use ($event, $version, $riskScore, $input, $exception) {
                return $this->persistEvaluation(
                    event: $event,
                    version: $version,
                    mode: EvaluationMode::RulesOnly,
                    classification: EventClassification::Unclear,
                    confidence: 0.4,
                    riskScore: $riskScore,
                    explanationSummary: 'Falló el agente de IA; se evalúa solo con reglas. Error: '.$exception->getMessage(),
                    reasoningSteps: ['agent_error_fallback'],
                    keyFactors: ['error' => $exception->getMessage()],
                    modelUsed: 'rules_engine:1.0',
                    agentInputSnapshot: $input,
                    inferenceTokens: null,
                    inferenceLatencyMs: null,
                    inferenceCostEstimate: null,
                    inferenceStatus: InferenceStatus::Error,
                );
            });
        }

        $finalRiskScore = round(max(0.0, min(1.0, $riskScore + $result->riskScoreDelta)), 2);

        return DB::transaction(function () use ($event, $version, $result, $finalRiskScore, $input) {
            $evaluation = $this->persistEvaluation(
                event: $event,
                version: $version,
                mode: EvaluationMode::AiText,
                classification: $result->classification,
                confidence: $result->confidenceScore,
                riskScore: $finalRiskScore,
                explanationSummary: $result->explanationSummary,
                reasoningSteps: $result->reasoningSteps,
                keyFactors: $result->keyFactors,
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

        AIEvaluationCompleted::dispatch($evaluation);

        return $evaluation;
    }

    private function priorityFor(EventClassification $classification, float $riskScore): EvaluationPriority
    {
        if (! $classification->isActionable()) {
            return EvaluationPriority::Low;
        }

        return match (true) {
            $riskScore >= 0.85 => EvaluationPriority::Urgent,
            $riskScore >= 0.6 => EvaluationPriority::High,
            $riskScore >= 0.3 => EvaluationPriority::Normal,
            default => EvaluationPriority::Low,
        };
    }

    private function nextVersion(int $normalizedEventId): int
    {
        return (int) AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEventId)
            ->max('evaluation_version') + 1;
    }

    private function quotaExceeded(int $teamId, int $monthlyLimit): bool
    {
        $periodKey = now()->format('Y-m');

        $meterIds = UsageMeter::whereIn('code', ['ai_tokens_in', 'ai_tokens_out'])
            ->pluck('id');

        if ($meterIds->isEmpty()) {
            return false;
        }

        $consumed = (int) UsageEvent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('usage_meter_id', $meterIds)
            ->where('billing_period_key', $periodKey)
            ->sum('quantity');

        return $consumed >= $monthlyLimit;
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
