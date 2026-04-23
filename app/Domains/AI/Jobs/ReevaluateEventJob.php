<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
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
        public readonly int $reevaluationRequestId,
    ) {
        $this->onQueue('ai-evaluation');
    }

    public function handle(
        ReevaluateEventWithNewEvidence $reevaluate,
        RecordUsageEvent $recordUsage,
    ): void {
        $request = AIReevaluationRequest::withoutGlobalScopes()->find($this->reevaluationRequestId);

        if ($request === null || $request->status !== ReevaluationStatus::Pending) {
            return;
        }

        $event = NormalizedEvent::withoutGlobalScopes()->find($request->normalized_event_id);

        if ($event === null) {
            $request->forceFill([
                'status' => ReevaluationStatus::Skipped,
                'processed_at' => now(),
            ])->save();

            return;
        }

        $request->forceFill([
            'status' => ReevaluationStatus::Processing,
        ])->save();

        $evaluation = $reevaluate->execute($event, $request->trigger_type->value);

        $recordUsage->execute(
            teamId: $event->team_id,
            meterCode: 'ai_calls',
            quantity: 1,
            eventKey: "ai_call:{$evaluation->id}",
        );

        $request->forceFill([
            'status' => ReevaluationStatus::Completed,
            'processed_at' => now(),
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ReevaluateEventJob failed', [
            'request_id' => $this->reevaluationRequestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
