<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventMediaContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateEventMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

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
            return;
        }

        $mediaIds = array_values(array_unique($this->mediaContextIds));

        if ($mediaIds === []) {
            return;
        }

        $mediaContexts = EventMediaContext::withoutGlobalScopes()
            ->where('normalized_event_id', $evaluation->normalized_event_id)
            ->whereIn('id', $mediaIds)
            ->get();

        if ($mediaContexts->isEmpty()) {
            return;
        }

        $multimodal->execute($evaluation, $mediaContexts);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('EvaluateEventMediaJob failed', [
            'evaluation_id' => $this->evaluationId,
            'media_context_ids' => $this->mediaContextIds,
            'error' => $exception->getMessage(),
        ]);
    }
}
