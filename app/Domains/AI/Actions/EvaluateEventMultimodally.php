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
use App\Support\SystemLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class EvaluateEventMultimodally
{
    /**
     * Lock por media para que dos jobs no paguen la misma imagen. Dura más
     * que el timeout de EvaluateEventMediaJob (180s) por si el worker muere.
     */
    private const MEDIA_LOCK_PREFIX = 'ai-media-assessment:';

    private const MEDIA_LOCK_SECONDS = 240;

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
            SystemLog::skipped('ai.media.batch_skipped', reason: 'no_media', input: ['evaluation_id' => $evaluation->id], debug: true);

            return collect();
        }

        $receivedCount = $mediaContexts->count();

        // Solo imágenes: el modelo no interpreta video ni audio, y enviarlos
        // como documento base64 se paga sin obtener señal. El archivo excluido
        // sigue siendo evidencia del incidente, no se borra.
        $isImage = fn ($item): bool => in_array(
            $item->media_type,
            [MediaType::Image, MediaType::Snapshot, null],
            true,
        );

        $excludedTypes = $mediaContexts->reject($isImage)
            ->map(fn ($item): string => $item->media_type->value)
            ->unique()
            ->values()
            ->all();

        $mediaContexts = $mediaContexts->filter($isImage)->values();

        if ($excludedTypes !== []) {
            SystemLog::skipped(
                'ai.media.filtered',
                reason: 'non_image_media',
                input: ['evaluation_id' => $evaluation->id],
                calc: [
                    'received_count' => $receivedCount,
                    'image_count' => $mediaContexts->count(),
                    'excluded_count' => $receivedCount - $mediaContexts->count(),
                    'excluded_media_types' => $excludedTypes,
                ],
            );
        }

        $event = $evaluation->normalizedEvent()->with(['eventType', 'eventSeverity'])->first();

        /** @var Collection<int, AIMediaAssessment> $assessments */
        $assessments = collect();

        /** @var Collection<int, AIMediaAssessment> $createdAssessments */
        $createdAssessments = collect();

        $retryableFailure = null;
        $reusedCount = 0;
        /** @var array<string, int> $skippedByReason cada media saltada cuenta una sola vez */
        $skippedByReason = [];
        $countSkip = function (string $reason) use (&$skippedByReason): void {
            $skippedByReason[$reason] = ($skippedByReason[$reason] ?? 0) + 1;
        };
        $remainingSlots = $this->remainingImageSlots($evaluation);
        $slotsAtStart = $remainingSlots;
        $profile = $this->resolveTenantProfile->execute((int) $evaluation->team_id);

        foreach ($mediaContexts as $media) {
            $existing = AIMediaAssessment::query()
                ->where('evaluation_id', $evaluation->id)
                ->where('event_media_context_id', $media->id)
                ->first();

            if ($existing !== null) {
                SystemLog::skipped(
                    'ai.media.reused',
                    reason: 'already_assessed_same_evaluation',
                    input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id],
                    result: ['assessment_id' => $existing->id, 'assessment_result' => $existing->result->value],
                    debug: true,
                );

                $assessments->push($existing);
                $reusedCount++;

                continue;
            }

            // La misma media ya evaluada con resultado concluyente bajo otra
            // versión de la evaluación del evento: no se vuelve a pagar. Los
            // veredictos se leen a través de todas las versiones (fusión y
            // hechos de decisión), así que reutilizarlo no pierde señal.
            $prior = $this->priorConclusiveAssessment($evaluation, $media);

            if ($prior !== null) {
                $this->logPriorReused($evaluation, $media, $prior, 'before_lock');

                $assessments->push($prior);
                $reusedCount++;

                continue;
            }

            // Dos jobs pueden cruzarse sobre la misma media (p. ej. el barrido
            // de pendientes de v1 y el de v2): solo uno llama al modelo; el
            // otro la salta y quien tiene el lock la persiste o la reintenta.
            $lock = Cache::lock(self::MEDIA_LOCK_PREFIX.$media->id, self::MEDIA_LOCK_SECONDS);

            if (! $lock->get()) {
                SystemLog::skipped('ai.media.assessment_skipped', reason: 'in_progress', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id]);
                $countSkip('in_progress');

                continue;
            }

            try {
                // Re-chequeo dentro del lock: otro worker pudo terminarla justo antes.
                $prior = $this->priorConclusiveAssessment($evaluation, $media);

                if ($prior !== null) {
                    $this->logPriorReused($evaluation, $media, $prior, 'after_lock');

                    $assessments->push($prior);
                    $reusedCount++;

                    continue;
                }

                if ($remainingSlots <= 0) {
                    SystemLog::skipped('ai.media.assessment_skipped', reason: 'image_cap_reached', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], calc: ['max_images_per_event' => $this->maxImagesPerEvent()]);
                    $countSkip('image_cap_reached');

                    continue;
                }

                // Misma cuota que el texto: un evento crítico siempre pasa.
                if ($event !== null && $this->quota->blocks($event, $profile, purpose: 'vision')) {
                    SystemLog::skipped('ai.media.assessment_skipped', reason: 'quota_exceeded', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id]);
                    $countSkip('quota_exceeded');

                    continue;
                }

                $assessmentType = $this->resolveAssessmentType($media->media_type);
                $input = $this->buildInput($evaluation, $event, $media, $assessmentType);

                try {
                    $output = $this->agent->assess($input);
                } catch (MediaFileMissingException $exception) {
                    SystemLog::skipped('ai.media.assessment_skipped', reason: 'file_missing', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], result: ['error_class' => $exception::class]);
                    $countSkip('file_missing');

                    continue;
                } catch (MediaFileRejectedException $exception) {
                    $assessment = $this->persistRejected($evaluation, $media, $assessmentType, $exception);

                    $assessments->push($assessment);
                    $createdAssessments->push($assessment);
                    $remainingSlots--;

                    continue;
                } catch (Throwable $exception) {
                    if (! $finalAttempt && RetryableAIError::isRetryable($exception)) {
                        SystemLog::degraded('ai.media.assessment_retry', reason: 'transient_failure', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], error: $exception);

                        $retryableFailure ??= $exception;
                        $countSkip('transient_failure');

                        continue;
                    }

                    SystemLog::degraded('ai.media.assessment_unavailable', reason: 'agent_error', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id], error: $exception);

                    $assessment = DB::transaction(fn () => AIMediaAssessment::create([
                        'evaluation_id' => $evaluation->id,
                        'event_media_context_id' => $media->id,
                        'media_type' => $media->media_type ?? MediaType::Snapshot,
                        'assessment_type' => $assessmentType,
                        'result' => MediaAssessmentResult::Unavailable,
                        'confidence_score' => 0.0,
                        // Mensaje crudo solo en el log; el operador ve un texto genérico.
                        'extracted_signals_json' => ['error_class' => class_basename($exception)],
                        'summary_text' => 'El análisis visual no estuvo disponible para esta media.',
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

                // Después del commit: el assessment y su uso ya están persistidos.
                SystemLog::ok(
                    'ai.media.assessed',
                    input: [
                        'evaluation_id' => $evaluation->id,
                        'event_media_context_id' => $media->id,
                        'assessment_type' => $assessmentType->value,
                        'media_type' => ($media->media_type ?? MediaType::Snapshot)->value,
                    ],
                    calc: [
                        'remaining_slots_before' => $remainingSlots,
                        'max_images_per_event' => $this->maxImagesPerEvent(),
                    ],
                    result: [
                        'assessment_id' => $assessment->id,
                        'assessment_result' => $output->result->value,
                        'confidence' => round($output->confidenceScore, 2),
                        'visible_threat' => ($output->extractedSignals['visible_threat'] ?? null) === true,
                        'model' => $output->modelUsed,
                        'input_tokens' => $output->inputTokens,
                        'output_tokens' => $output->outputTokens,
                        'cost_estimate' => $output->costEstimate,
                        'latency_ms' => $output->latencyMs,
                    ],
                );

                $assessments->push($assessment);
                $createdAssessments->push($assessment);
                $remainingSlots--;
            } finally {
                $lock->release();
            }
        }

        $modeBefore = $evaluation->evaluation_mode?->value;

        if ($assessments->isNotEmpty()) {
            $this->promoteEvaluationMode($evaluation);
            $this->refreshInferenceMediaCount($evaluation);
        }

        // Conciliable: image_count = reused_count + created_count + skipped_count.
        $batchInput = ['evaluation_id' => $evaluation->id];
        $batchCalc = [
            'received_count' => $receivedCount,
            'image_count' => $mediaContexts->count(),
            'remaining_slots_at_start' => $slotsAtStart,
            'max_images_per_event' => $this->maxImagesPerEvent(),
        ];
        $batchResult = [
            'reused_count' => $reusedCount,
            'created_count' => $createdAssessments->count(),
            'skipped_count' => array_sum($skippedByReason),
            'skipped_by_reason' => $skippedByReason,
            'retry_pending' => $retryableFailure !== null,
            'mode_before' => $modeBefore,
            'mode_after' => $evaluation->evaluation_mode?->value,
        ];

        if ($retryableFailure !== null) {
            SystemLog::degraded('ai.media.batch_completed', reason: 'retry_pending', input: $batchInput, calc: $batchCalc, result: $batchResult);
        } else {
            SystemLog::ok('ai.media.batch_completed', input: $batchInput, calc: $batchCalc, result: $batchResult);
        }

        if ($createdAssessments->isNotEmpty()) {
            MediaAssessmentCompleted::dispatch($evaluation, $createdAssessments);
        }

        if ($retryableFailure !== null) {
            throw $retryableFailure;
        }

        return $assessments;
    }

    private function logPriorReused(AIEventEvaluation $evaluation, EventMediaContext $media, AIMediaAssessment $prior, string $checked): void
    {
        SystemLog::skipped(
            'ai.media.reused',
            reason: 'prior_conclusive_assessment',
            input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id],
            calc: ['checked' => $checked],
            result: [
                'assessment_id' => $prior->id,
                'prior_evaluation_id' => $prior->evaluation_id,
                'assessment_result' => $prior->result->value,
            ],
        );
    }

    private function maxImagesPerEvent(): int
    {
        return max(0, (int) config('ai.media.max_images_per_event', 8));
    }

    /**
     * Images still assessable for this event: the cap counts distinct media
     * already assessed across every evaluation version of the event.
     */
    /**
     * Evaluación previa concluyente de esta media bajo cualquier versión de la
     * evaluación del evento. `unavailable` no cuenta: fue un fallo del
     * proveedor y la media merece otro intento.
     */
    private function priorConclusiveAssessment(AIEventEvaluation $evaluation, EventMediaContext $media): ?AIMediaAssessment
    {
        return AIMediaAssessment::query()
            ->whereIn(
                'evaluation_id',
                AIEventEvaluation::query()
                    ->where('normalized_event_id', $evaluation->normalized_event_id)
                    ->select('id'),
            )
            ->where('event_media_context_id', $media->id)
            ->where('result', '!=', MediaAssessmentResult::Unavailable->value)
            ->orderByDesc('id')
            ->first();
    }

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
        SystemLog::skipped('ai.media.assessment_rejected', reason: 'rejected_before_model', input: ['evaluation_id' => $evaluation->id, 'event_media_context_id' => $media->id, 'rejection' => (string) $exception->reason]);

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
