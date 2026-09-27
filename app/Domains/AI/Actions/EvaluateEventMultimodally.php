<?php

namespace App\Domains\AI\Actions;

use App\Contracts\AI\Exceptions\MediaFileMissingException;
use App\Contracts\AI\Exceptions\MediaFileRejectedException;
use App\Contracts\AI\MediaAssessmentAgent;
use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Support\MediaCaptureContext;
use App\Domains\AI\Support\RetryableAIError;
use App\Domains\AI\Support\TenantAIQuota;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateEventMultimodally
{
    public function __construct(
        private readonly MediaAssessmentAgent $agent,
        private readonly RecordUsageEvent $recordUsageEvent,
        private readonly TenantAIQuota $quota,
        private readonly ResolveTenantAIProfile $resolveTenantProfile,
    ) {}

    /**
     * Run the multimodal pipeline against a set of media assets attached to an
     * existing `AIEventEvaluation`. Idempotent per (evaluation_id, media_id).
     *
     * Vision failures are split by nature (roadmap: vision retries):
     * - file not on storage → skipped, nothing persisted (it may still land);
     * - invalid/oversize image → `low_quality` row, no model call;
     * - transient provider error (429, 5xx, timeout, connection) → rethrown
     *   after the rest of the batch so the job retries, unless this is the
     *   final attempt, where it is recorded as `unavailable` like any other
     *   non-retryable error.
     *
     * At most `ai.media.max_images_per_event` images are assessed per event,
     * and none while the tenant is over its AI quota (critical events bypass
     * the quota, like text evaluation).
     *
     * @param  Collection<int, EventMediaContext>  $mediaContexts
     * @param  bool  $finalAttempt  False while the calling job still has retries left.
     * @return Collection<int, AIMediaAssessment>
     *
     * @throws Throwable the first transient provider error when `$finalAttempt` is false
     */
    public function execute(AIEventEvaluation $evaluation, Collection $mediaContexts, bool $finalAttempt = true): Collection
    {
        if ($mediaContexts->isEmpty()) {
            return collect();
        }

        // Solo imágenes: el modelo no interpreta video ni audio, y enviarlos
        // como documento base64 se paga sin obtener señal. El archivo excluido
        // sigue siendo evidencia del incidente, no se borra.
        $mediaContexts = $mediaContexts->filter(fn ($item) => in_array(
            $item->media_type,
            [MediaType::Image, MediaType::Snapshot, null],
            true,
        ))->values();

        $event = $evaluation->normalizedEvent()->with(['eventType', 'eventSeverity'])->first();

        /** @var Collection<int, AIMediaAssessment> $assessments */
        $assessments = collect();

        /** @var Collection<int, AIMediaAssessment> $createdAssessments */
        $createdAssessments = collect();

        $retryableFailure = null;
        $remainingSlots = $this->remainingImageSlots($evaluation);
        $profile = $this->resolveTenantProfile->execute((int) $evaluation->team_id);

        foreach ($mediaContexts as $media) {
            $existing = AIMediaAssessment::query()
                ->where('evaluation_id', $evaluation->id)
                ->where('event_media_context_id', $media->id)
                ->first();

            if ($existing !== null) {
                $assessments->push($existing);

                continue;
            }

            if ($remainingSlots <= 0) {
                Log::info('Media assessment skipped: per-event image cap reached', [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $media->id,
                    'max_images_per_event' => $this->maxImagesPerEvent(),
                ]);

                continue;
            }

            // Misma cuota que el texto: un evento crítico siempre pasa.
            if ($event !== null && $this->quota->blocks($event, $profile)) {
                Log::info('Media assessment skipped: tenant AI quota exceeded', [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $media->id,
                ]);

                continue;
            }

            $assessmentType = $this->resolveAssessmentType($media->media_type);
            $input = $this->buildInput($evaluation, $event, $media, $assessmentType);

            try {
                $output = $this->agent->assess($input);
            } catch (MediaFileMissingException $exception) {
                Log::info('Media assessment skipped: file not on storage', [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $media->id,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            } catch (MediaFileRejectedException $exception) {
                $assessment = $this->persistRejected($evaluation, $media, $assessmentType, $exception);

                $assessments->push($assessment);
                $createdAssessments->push($assessment);
                $remainingSlots--;

                continue;
            } catch (Throwable $exception) {
                if (! $finalAttempt && RetryableAIError::isRetryable($exception)) {
                    Log::warning('MediaAssessmentAgent transient failure; will retry', [
                        'evaluation_id' => $evaluation->id,
                        'event_media_context_id' => $media->id,
                        'error' => $exception->getMessage(),
                    ]);

                    $retryableFailure ??= $exception;

                    continue;
                }

                Log::warning('MediaAssessmentAgent failed; recording unavailable assessment', [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $media->id,
                    'error' => $exception->getMessage(),
                ]);

                $assessment = DB::transaction(fn () => AIMediaAssessment::create([
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $media->id,
                    'media_type' => $media->media_type ?? MediaType::Snapshot,
                    'assessment_type' => $assessmentType,
                    'result' => MediaAssessmentResult::Unavailable,
                    'confidence_score' => 0.0,
                    'extracted_signals_json' => ['error' => $exception->getMessage()],
                    'summary_text' => 'Falló el agente multimodal: '.$exception->getMessage(),
                    'latency_ms' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'cost_estimate' => null,
                    'model_used' => 'media-agent:error',
                    'assessed_at' => now(),
                ]));

                $assessments->push($assessment);
                $createdAssessments->push($assessment);
                $remainingSlots--;

                continue;
            }

            $assessment = DB::transaction(function () use ($evaluation, $media, $assessmentType, $output) {
                $created = AIMediaAssessment::create([
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $media->id,
                    'media_type' => $media->media_type ?? MediaType::Snapshot,
                    'assessment_type' => $assessmentType,
                    'result' => $output->result,
                    'confidence_score' => round($output->confidenceScore, 2),
                    'extracted_signals_json' => $output->extractedSignals,
                    'summary_text' => $output->summaryText,
                    'latency_ms' => $output->latencyMs,
                    'input_tokens' => $output->inputTokens,
                    'output_tokens' => $output->outputTokens,
                    'cost_estimate' => $output->costEstimate,
                    'model_used' => $output->modelUsed,
                    'assessed_at' => now(),
                ]);

                $this->recordMultimodalUsage($evaluation, $created, $output->inputTokens, $output->outputTokens);

                return $created;
            });

            $assessments->push($assessment);
            $createdAssessments->push($assessment);
            $remainingSlots--;
        }

        if ($assessments->isNotEmpty()) {
            $this->promoteEvaluationMode($evaluation);
            $this->refreshInferenceMediaCount($evaluation);
        }

        if ($createdAssessments->isNotEmpty()) {
            MediaAssessmentCompleted::dispatch($evaluation, $createdAssessments);
        }

        if ($retryableFailure !== null) {
            throw $retryableFailure;
        }

        return $assessments;
    }

    private function maxImagesPerEvent(): int
    {
        return max(0, (int) config('ai.media.max_images_per_event', 8));
    }

    /**
     * Images still assessable for this event: the cap counts distinct media
     * already assessed across every evaluation version of the event.
     */
    private function remainingImageSlots(AIEventEvaluation $evaluation): int
    {
        $assessed = AIMediaAssessment::query()
            ->whereIn(
                'evaluation_id',
                AIEventEvaluation::query()
                    ->where('normalized_event_id', $evaluation->normalized_event_id)
                    ->select('id'),
            )
            ->distinct()
            ->count('event_media_context_id');

        return $this->maxImagesPerEvent() - $assessed;
    }

    private function persistRejected(
        AIEventEvaluation $evaluation,
        EventMediaContext $media,
        MediaAssessmentType $assessmentType,
        MediaFileRejectedException $exception,
    ): AIMediaAssessment {
        Log::info('Media assessment rejected before model call', [
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
            'reason' => $exception->reason,
        ]);

        return DB::transaction(fn () => AIMediaAssessment::create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
            'media_type' => $media->media_type ?? MediaType::Snapshot,
            'assessment_type' => $assessmentType,
            'result' => MediaAssessmentResult::LowQuality,
            'confidence_score' => 0.0,
            'extracted_signals_json' => ['rejected_reason' => $exception->reason],
            'summary_text' => 'Imagen descartada antes del análisis: '.$exception->getMessage(),
            'latency_ms' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_estimate' => null,
            'model_used' => 'media-validator',
            'assessed_at' => now(),
        ]));
    }

    private function resolveAssessmentType(?MediaType $mediaType): MediaAssessmentType
    {
        return match ($mediaType) {
            MediaType::Audio => MediaAssessmentType::AudioCheck,
            MediaType::Clip, MediaType::Video => MediaAssessmentType::ClipReview,
            MediaType::Image, MediaType::Snapshot, null => MediaAssessmentType::VisualValidation,
        };
    }

    private function buildInput(
        AIEventEvaluation $evaluation,
        ?NormalizedEvent $event,
        EventMediaContext $media,
        MediaAssessmentType $assessmentType,
    ): MediaAssessmentInput {
        $metadata = (array) ($media->metadata_json ?? []);

        return new MediaAssessmentInput(
            teamId: $evaluation->team_id,
            evaluationId: $evaluation->id,
            mediaContextId: $media->id,
            mediaType: $media->media_type ?? MediaType::Snapshot,
            assessmentType: $assessmentType,
            storagePath: $media->storage_path,
            mimeType: $media->mime_type,
            sizeBytes: $media->size_bytes,
            durationSeconds: $media->duration_seconds,
            mediaMetadata: $metadata,
            eventContext: [
                'normalized_event_id' => $evaluation->normalized_event_id,
                'event_type_code' => $event?->eventType?->code,
                'event_type_name' => $event?->eventType?->name,
                'severity' => $event?->eventSeverity?->code,
                'occurred_at' => $event?->occurred_at?->toIso8601String(),
                'evaluation_version' => $evaluation->evaluation_version,
                'classification' => $evaluation->classification?->value,
                'risk_score' => $evaluation->risk_score,
            ],
            cameraSide: MediaCaptureContext::cameraSide($metadata),
            captureOffsetSeconds: MediaCaptureContext::captureOffsetSeconds($metadata, $event?->occurred_at),
        );
    }

    private function promoteEvaluationMode(AIEventEvaluation $evaluation): void
    {
        $current = $evaluation->evaluation_mode;

        $next = match ($current) {
            EvaluationMode::Multimodal, EvaluationMode::Hybrid => $current,
            EvaluationMode::AiText => EvaluationMode::Multimodal,
            default => EvaluationMode::Hybrid,
        };

        if ($next === $current) {
            return;
        }

        $evaluation->forceFill(['evaluation_mode' => $next])->save();
    }

    private function refreshInferenceMediaCount(AIEventEvaluation $evaluation): void
    {
        $count = AIMediaAssessment::query()
            ->where('evaluation_id', $evaluation->id)
            ->count();

        AIInferenceLog::query()
            ->where('evaluation_id', $evaluation->id)
            ->update(['media_assets_count' => $count]);
    }

    private function recordMultimodalUsage(
        AIEventEvaluation $evaluation,
        AIMediaAssessment $assessment,
        int $inputTokens,
        int $outputTokens,
    ): void {
        if (UsageMeter::where('code', 'ai_calls')->exists()) {
            $this->recordUsageEvent->execute(
                teamId: $evaluation->team_id,
                meterCode: 'ai_calls',
                quantity: 1,
                eventKey: 'ai_call:media:'.$evaluation->id.':'.$assessment->event_media_context_id,
                metadata: [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $assessment->event_media_context_id,
                    'channel' => 'multimodal',
                ],
            );
        }

        if ($inputTokens > 0 && UsageMeter::where('code', 'ai_tokens_in')->exists()) {
            $this->recordUsageEvent->execute(
                teamId: $evaluation->team_id,
                meterCode: 'ai_tokens_in',
                quantity: $inputTokens,
                eventKey: 'ai_tokens_in:media:'.$assessment->id,
                metadata: [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $assessment->event_media_context_id,
                ],
            );
        }

        if ($outputTokens > 0 && UsageMeter::where('code', 'ai_tokens_out')->exists()) {
            $this->recordUsageEvent->execute(
                teamId: $evaluation->team_id,
                meterCode: 'ai_tokens_out',
                quantity: $outputTokens,
                eventKey: 'ai_tokens_out:media:'.$assessment->id,
                metadata: [
                    'evaluation_id' => $evaluation->id,
                    'event_media_context_id' => $assessment->event_media_context_id,
                ],
            );
        }
    }
}
