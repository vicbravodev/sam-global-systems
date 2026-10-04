<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Data\ClefDecision;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Infrastructure\AI\Clef\ClefEventDecider;
use App\Infrastructure\AI\Clef\ClefImageLoader;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Evalúa en sombra, con cada modelo Clef configurado, lo mismo que evaluó
 * GPT. Nunca toca la evaluación oficial ni cobra al tenant. Idempotente por
 * (evaluación, modelo, versión de schema): un reintento sólo llama a los
 * modelos que aún no tienen fila.
 */
class ShadowEvaluateWithClefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $teamId,
        public readonly int $evaluationId,
        public readonly string $source = AIShadowEvaluation::SOURCE_LIVE,
    ) {
        $this->onQueue('default');
    }

    public function handle(ClefEventDecider $decider, ClefImageLoader $images): void
    {
        $evaluation = AIEventEvaluation::withoutGlobalScopes()->find($this->evaluationId);

        if ($evaluation === null || $evaluation->team_id !== $this->teamId) {
            SystemLog::skipped(
                'ai.clef_shadow.skipped',
                reason: $evaluation === null ? 'evaluation_missing' : 'team_mismatch',
                input: ['evaluation_id' => $this->evaluationId, 'team_id' => $this->teamId],
            );

            return;
        }

        TenantContext::for($this->teamId, function () use ($evaluation, $decider, $images): void {
            $snapshot = AIInferenceLog::query()
                ->where('evaluation_id', $evaluation->id)
                ->orderBy('id')
                ->value('input_snapshot_json');
            $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

            if (! is_array($snapshot) || $snapshot === []) {
                SystemLog::skipped('ai.clef_shadow.skipped', reason: 'no_snapshot', input: ['evaluation_id' => $evaluation->id]);

                return;
            }

            $pending = $this->pendingModels($evaluation);

            if ($pending === []) {
                return;
            }

            $loaded = (bool) config('ai.clef.send_images', true)
                ? $images->forEvent($evaluation->id, $evaluation->normalized_event_id)['images']
                : [];

            $retryable = null;

            foreach ($pending as $model) {
                try {
                    $decision = $decider->decide($model, $snapshot, $loaded);
                } catch (ClefRequestFailedException $e) {
                    if ($e->retryable && $this->attempts() < $this->tries) {
                        SystemLog::degraded(
                            'ai.clef_shadow.failed',
                            reason: $e->reason,
                            input: ['evaluation_id' => $evaluation->id, 'model' => $model, 'attempt' => $this->attempts()],
                            error: $e,
                        );
                        $retryable = $e;

                        continue;
                    }

                    $this->persistFailure($evaluation, $model, $e->reason, $e);

                    continue;
                } catch (Throwable $e) {
                    $this->persistFailure($evaluation, $model, class_basename($e), $e);

                    continue;
                }

                $this->persistSuccess($evaluation, $decision);
            }

            if ($retryable !== null) {
                throw $retryable;
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['evaluation_id' => $this->evaluationId]);
    }

    /**
     * @return list<string>
     */
    private function pendingModels(AIEventEvaluation $evaluation): array
    {
        /** @var list<string> $configured */
        $configured = array_values((array) config('ai.clef.models', []));

        $done = AIShadowEvaluation::query()
            ->where('ai_event_evaluation_id', $evaluation->id)
            ->where('schema_version', ClefQuestionSchema::VERSION)
            ->pluck('model')
            ->all();

        foreach (array_intersect($configured, $done) as $model) {
            SystemLog::skipped('ai.clef_shadow.skipped', reason: 'already_evaluated', input: ['evaluation_id' => $evaluation->id, 'model' => $model]);
        }

        return array_values(array_diff($configured, $done));
    }

    private function persistSuccess(AIEventEvaluation $evaluation, ClefDecision $decision): void
    {
        AIShadowEvaluation::create([
            'team_id' => $evaluation->team_id,
            'ai_event_evaluation_id' => $evaluation->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'model' => $decision->model,
            'schema_version' => ClefQuestionSchema::VERSION,
            'source' => $this->source,
            'status' => AIShadowEvaluation::STATUS_SUCCESS,
            'classification' => $decision->classification,
            'classification_probabilities_json' => $decision->classificationProbabilities,
            'risk_score' => $decision->riskScore,
            'needs_human_probability' => $decision->needsHumanProbability,
            'media_answers_json' => $decision->mediaAnswers,
            'images_sent' => $decision->imagesSent,
            'input_tokens' => $decision->inputTokens,
            'output_tokens' => $decision->outputTokens,
            'latency_ms' => $decision->latencyMs,
            'cost_estimate' => $decision->costEstimate,
        ]);

        $prices = (array) config('ai.clef.pricing_per_million_input', []);

        SystemLog::ok(
            'ai.clef_shadow.completed',
            input: ['evaluation_id' => $evaluation->id, 'model' => $decision->model, 'source' => $this->source, 'schema_version' => ClefQuestionSchema::VERSION],
            calc: [
                'input_tokens' => $decision->inputTokens,
                'price_per_million_input' => (float) ($prices[$decision->model] ?? 0.0),
                'cost_estimate' => $decision->costEstimate,
                'latency_ms' => $decision->latencyMs,
                'images_sent' => $decision->imagesSent,
                'gpt_classification' => $evaluation->classification?->value,
                'matches_gpt' => $decision->classification === $evaluation->classification?->value,
            ],
            result: [
                'classification' => $decision->classification,
                'classification_probability' => $decision->classificationProbabilities[$decision->classification] ?? null,
                'risk_score' => $decision->riskScore,
            ],
        );
    }

    private function persistFailure(AIEventEvaluation $evaluation, string $model, string $errorCode, Throwable $e): void
    {
        AIShadowEvaluation::create([
            'team_id' => $evaluation->team_id,
            'ai_event_evaluation_id' => $evaluation->id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'model' => $model,
            'schema_version' => ClefQuestionSchema::VERSION,
            'source' => $this->source,
            'status' => AIShadowEvaluation::STATUS_FAILED,
            'error_code' => substr($errorCode, 0, 64),
        ]);

        SystemLog::failed(
            'ai.clef_shadow.failed',
            reason: $errorCode,
            input: ['evaluation_id' => $evaluation->id, 'model' => $model, 'attempt' => $this->attempts()],
            error: $e,
        );
    }
}
