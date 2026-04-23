<?php

namespace App\Domains\AI\Actions;

use App\Contracts\AiProviderAdapter;
use App\Contracts\NullImplementations\NullAiProviderAdapter;
use App\Domains\AI\Enums\InferenceStatus;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Events\AIEvaluationCompletedBroadcast;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIExplanation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIRecommendedAction;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class EvaluateEventWithAI
{
    public function __construct(
        private readonly BuildAIInputContext $buildInput,
        private readonly CalculateRiskScore $calculateRiskScore,
        private readonly DetectFalsePositive $detectFalsePositive,
        private readonly GenerateRecommendedActions $generateRecommendedActions,
        private readonly AiProviderAdapter $aiAdapter,
    ) {}

    public function execute(
        NormalizedEvent $event,
        EventContextSnapshot $context,
        ?OperationalContextProfile $profile = null,
        int $evaluationVersion = 1,
    ): AIEventEvaluation {
        $profile = $profile ?? OperationalContextProfile::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        $input = $this->buildInput->execute($event, $context, $profile);

        $start = microtime(true);

        try {
            $result = $this->aiAdapter->evaluate($input);
            $status = InferenceStatus::Success;
            $errorOutput = null;
        } catch (Throwable $exception) {
            $result = app(NullAiProviderAdapter::class)->evaluate($input);
            $status = InferenceStatus::Error;
            $errorOutput = ['error' => $exception->getMessage()];
        }

        $latencyMs = (int) ((microtime(true) - $start) * 1000);

        $riskScore = $this->calculateRiskScore->execute($event, $context, $profile);

        return DB::transaction(function () use (
            $event,
            $context,
            $input,
            $result,
            $status,
            $errorOutput,
            $latencyMs,
            $riskScore,
            $evaluationVersion,
        ) {
            $evaluation = AIEventEvaluation::query()->create([
                'normalized_event_id' => $event->id,
                'team_id' => $event->team_id,
                'evaluation_version' => $evaluationVersion,
                'evaluation_mode' => $result->mode,
                'classification' => $result->classification,
                'confidence_score' => $result->confidenceScore,
                'risk_score' => $riskScore,
                'priority_level' => $result->priorityLevel,
                'is_real_event' => $result->isRealEvent,
                'requires_action' => $result->requiresAction,
                'recommended_action' => null,
                'explanation_text' => $result->explanationSummary,
                'signals_json' => $context->signals_json ?? [],
                'evidence_summary_json' => $result->evidenceSummary,
                'model_used' => $result->modelUsed,
                'evaluated_at' => now(),
            ]);

            foreach ($result->signals as $signal) {
                $evaluation->decisionSignals()->create([
                    'signal_code' => $signal['code'],
                    'signal_value' => $signal['value'],
                    'weight' => $signal['weight'] ?? null,
                    'description' => $signal['description'] ?? null,
                ]);
            }

            AIExplanation::query()->create([
                'evaluation_id' => $evaluation->id,
                'summary' => $result->explanationSummary,
                'reasoning_steps_json' => $result->reasoningSteps,
                'key_factors_json' => $result->keyFactors,
                'confidence_breakdown_json' => $result->confidenceBreakdown,
                'evidence_used_json' => $result->evidenceSummary,
            ]);

            AIInferenceLog::query()->create([
                'evaluation_id' => $evaluation->id,
                'input_snapshot_json' => $input,
                'output_json' => $errorOutput ?? [
                    'classification' => $result->classification->value,
                    'confidence' => $result->confidenceScore,
                    'risk_score' => $riskScore,
                ],
                'latency_ms' => $latencyMs,
                'tokens_used' => $result->tokensUsed,
                'media_assets_count' => 0,
                'cost_estimate' => $result->costEstimate,
                'status' => $status,
            ]);

            $recommended = $this->generateRecommendedActions->execute($evaluation);
            $topAction = $recommended->first();
            if ($topAction instanceof AIRecommendedAction) {
                $evaluation->forceFill(['recommended_action' => $topAction->action_type->value])->save();
            }

            $this->detectFalsePositive->execute($evaluation);

            AIEvaluationCompleted::dispatch($evaluation);
            AIEvaluationCompletedBroadcast::dispatch($evaluation);

            return $evaluation->fresh();
        });
    }
}
