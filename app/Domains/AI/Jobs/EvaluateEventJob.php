<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public function handle(EvaluateEventWithAI $evaluateEventWithAI, AIEvaluationGate $gate): void
    {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()
            ->with(['eventCategory', 'eventType'])
            ->find($this->normalizedEventId);

        if ($normalizedEvent === null) {
            SystemLog::skipped('ai.evaluation.skipped', reason: 'normalized_event_missing', input: ['normalized_event_id' => $this->normalizedEventId]);

            return;
        }

        PipelineTrace::adopt($normalizedEvent->trace_id, $normalizedEvent->team_id, ['normalized_event_id' => $normalizedEvent->id]);

        if (! $gate->allows($normalizedEvent, 'evaluate_job')) {
            return;
        }

        // Entra en el tenant del evento: la evaluación lee el perfil de IA del
        // tenant, su contexto y su cuota. Ver §2.1.
        TenantContext::for($normalizedEvent->team_id, function () use ($normalizedEvent, $evaluateEventWithAI) {
            $existingId = AIEventEvaluation::query()
                ->where('normalized_event_id', $normalizedEvent->id)
                ->value('id');

            if ($existingId !== null) {
                SystemLog::skipped('ai.evaluation.already_exists', reason: 'evaluation_exists', input: ['normalized_event_id' => $normalizedEvent->id], result: ['existing_evaluation_id' => $existingId]);

                return;
            }

            $evaluateEventWithAI->execute($normalizedEvent);
        });
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['normalized_event_id' => $this->normalizedEventId]);
    }
}
