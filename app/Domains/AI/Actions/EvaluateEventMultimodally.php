<?php

namespace App\Domains\AI\Actions;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
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
    ) {}

    /**
     * Run the multimodal pipeline against a set of media assets attached to an
     * existing `AIEventEvaluation`. Idempotent per (evaluation_id, media_id).
     *
     * @param  Collection<int, EventMediaContext>  $mediaContexts
     * @return Collection<int, AIMediaAssessment>
     */
    public function execute(AIEventEvaluation $evaluation, Collection $mediaContexts): Collection
    {
        if ($mediaContexts->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, AIMediaAssessment> $assessments */
        $assessments = collect();

        foreach ($mediaContexts as $media) {
            $existing = AIMediaAssessment::query()
                ->where('evaluation_id', $evaluation->id)
                ->where('event_media_context_id', $media->id)
                ->first();

            if ($existing !== null) {
                $assessments->push($existing);

                continue;
            }

            $assessmentType = $this->resolveAssessmentType($media->media_type);
            $input = $this->buildInput($evaluation, $media, $assessmentType);

            try {
                $output = $this->agent->assess($input);
            } catch (Throwable $exception) {
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
                    'summary_text' => 'Multimodal agent failed: '.$exception->getMessage(),
                    'latency_ms' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'cost_estimate' => null,
                    'model_used' => 'media-agent:error',
                    'assessed_at' => now(),
                ]));

                $assessments->push($assessment);

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
        }

        $this->promoteEvaluationMode($evaluation);
        $this->refreshInferenceMediaCount($evaluation);

        return $assessments;
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
        EventMediaContext $media,
        MediaAssessmentType $assessmentType,
    ): MediaAssessmentInput {
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
            mediaMetadata: (array) ($media->metadata_json ?? []),
            eventContext: [
                'normalized_event_id' => $evaluation->normalized_event_id,
                'evaluation_version' => $evaluation->evaluation_version,
                'classification' => $evaluation->classification?->value,
                'risk_score' => $evaluation->risk_score,
            ],
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
