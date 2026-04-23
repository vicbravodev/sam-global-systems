<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 60;

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

    public function handle(EvaluateEventWithAI $evaluate, RecordUsageEvent $recordUsage): void
    {
        $event = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($event === null) {
            return;
        }

        $context = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        if ($context === null) {
            Log::warning('EvaluateEventJob skipped: no EventContextSnapshot', [
                'normalized_event_id' => $event->id,
            ]);

            return;
        }

        $existing = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->where('evaluation_version', 1)
            ->first();

        if ($existing !== null) {
            return;
        }

        $evaluation = $evaluate->execute(
            event: $event,
            context: $context,
        );

        try {
            $recordUsage->execute(
                teamId: $event->team_id,
                meterCode: 'ai_calls',
                quantity: 1,
                eventKey: "ai_call:{$evaluation->id}",
            );
        } catch (ModelNotFoundException) {
            Log::warning('EvaluateEventJob skipped usage metering: ai_calls meter missing', [
                'normalized_event_id' => $event->id,
                'evaluation_id' => $evaluation->id,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('EvaluateEventJob failed', [
            'normalized_event_id' => $this->normalizedEventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
