<?php

namespace App\Domains\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SPEC-09-PR2-DEFERRED: Multimodal media evaluation is deferred until
 * spec 08 PR #2 (event_media_contexts) and the Laravel AI SDK are in place.
 * Once both are available, this job must load the evaluation + media
 * contexts and call EvaluateEventMultimodally.
 */
class EvaluateEventMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    /**
     * @param  array<int, int>  $mediaContextIds
     */
    public function __construct(
        public readonly int $evaluationId,
        public readonly array $mediaContextIds = [],
    ) {
        $this->onQueue('ai-evaluation');
    }

    public function handle(): void
    {
        Log::info('EvaluateEventMediaJob no-op (SPEC-09-PR2-DEFERRED)', [
            'evaluation_id' => $this->evaluationId,
            'media_context_ids' => $this->mediaContextIds,
        ]);
    }
}
