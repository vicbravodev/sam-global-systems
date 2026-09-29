<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventMediaContext;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateEventMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Transient vision errors (429, 5xx, timeouts) are rethrown by
     * `EvaluateEventMultimodally` until the last attempt, where they are
     * recorded as `unavailable`.
     */
    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<int, int>  $mediaContextIds
     */
    public function __construct(
        public readonly int $evaluationId,
        public readonly array $mediaContextIds,
    ) {
        $this->onQueue('ai-evaluation');
    }

    public function handle(EvaluateEventMultimodally $multimodal): void
    {
        $evaluation = AIEventEvaluation::withoutGlobalScopes()->find($this->evaluationId);

        if ($evaluation === null) {
            SystemLog::skipped('ai.media.job_skipped', reason: 'evaluation_missing', input: ['evaluation_id' => $this->evaluationId]);

            return;
        }

        PipelineTrace::adopt(null, $evaluation->team_id, [
            'normalized_event_id' => $evaluation->normalized_event_id,
            'ai_evaluation_id' => $evaluation->id,
        ]);

        $mediaIds = array_values(array_unique($this->mediaContextIds));

        if ($mediaIds === []) {
            SystemLog::skipped('ai.media.job_skipped', reason: 'no_media_ids', input: ['evaluation_id' => $evaluation->id]);

            return;
        }

        // Entra en el tenant de la evaluación: la media y el análisis son suyos.
        // Ver §2.1.
        TenantContext::for($evaluation->team_id, function () use ($evaluation, $mediaIds, $multimodal) {
            $mediaContexts = EventMediaContext::query()
                ->where('normalized_event_id', $evaluation->normalized_event_id)
                ->whereIn('id', $mediaIds)
                ->get();

            if ($mediaContexts->isEmpty()) {
                SystemLog::skipped('ai.media.job_skipped', reason: 'media_not_found', input: ['evaluation_id' => $evaluation->id], calc: ['requested_count' => count($mediaIds)]);

                return;
            }

            $multimodal->execute($evaluation, $mediaContexts, finalAttempt: $this->attempts() >= $this->tries);
        });
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['evaluation_id' => $this->evaluationId, 'media_context_ids' => $this->mediaContextIds]);
    }
}
