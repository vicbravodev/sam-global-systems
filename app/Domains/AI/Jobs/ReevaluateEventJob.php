<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReevaluateEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $normalizedEventId,
        public readonly string $triggerType,
        public readonly ?int $triggerReferenceId = null,
        public readonly ?string $reason = null,
    ) {
        $this->onQueue('ai-evaluation');
    }

    public function handle(ReevaluateEventWithNewEvidence $reevaluate): void
    {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($normalizedEvent === null) {
            return;
        }

        $trigger = ReevaluationTrigger::from($this->triggerType);

        $reevaluate->execute(
            event: $normalizedEvent,
            trigger: $trigger,
            triggerReferenceId: $this->triggerReferenceId,
            reason: $this->reason,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ReevaluateEventJob failed', [
            'normalized_event_id' => $this->normalizedEventId,
            'trigger_type' => $this->triggerType,
            'error' => $exception->getMessage(),
        ]);
    }
}
