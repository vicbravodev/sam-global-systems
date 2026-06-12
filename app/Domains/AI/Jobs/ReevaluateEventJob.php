<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReevaluateEventJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Safety TTL for the coalescing lock. It must outlive the media debounce
     * delay plus queue latency; once it expires a duplicate run is merely
     * redundant (re-evaluations are versioned, never destructive).
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $normalizedEventId,
        public readonly string $triggerType,
        public readonly ?int $triggerReferenceId = null,
        public readonly ?string $reason = null,
    ) {
        $this->onQueue('ai-evaluation');
    }

    /**
     * One pending re-evaluation per (event, trigger): bursts of deferred media
     * collapse into the job already waiting out its debounce delay. The lock
     * releases when processing starts, so evidence landing mid-run can still
     * schedule a fresh pass and the final media state always reaches a decision.
     */
    public function uniqueId(): string
    {
        return $this->normalizedEventId.':'.$this->triggerType;
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
