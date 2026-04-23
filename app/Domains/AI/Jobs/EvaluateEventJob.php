<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public function __construct(
        public readonly int $normalizedEventId,
    ) {
        $this->onQueue('ai-evaluation');
    }

    public function uniqueId(): string
    {
        return (string) $this->normalizedEventId;
    }

    public function handle(EvaluateEventWithAI $evaluateEventWithAI): void
    {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($normalizedEvent === null) {
            return;
        }

        if (AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEvent->id)
            ->exists()
        ) {
            return;
        }

        $evaluateEventWithAI->execute($normalizedEvent);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('EvaluateEventJob failed', [
            'normalized_event_id' => $this->normalizedEventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
