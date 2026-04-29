<?php

namespace App\Domains\Decisions\Jobs;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Models\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunDecisionEngineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $aiEvaluationId,
    ) {
        $this->onQueue('decisions');
    }

    public function uniqueId(): string
    {
        return (string) $this->aiEvaluationId;
    }

    public function handle(EvaluateDecisionRules $evaluateDecisionRules): void
    {
        $eval = AIEventEvaluation::withoutGlobalScopes()->find($this->aiEvaluationId);

        if ($eval === null) {
            return;
        }

        $existing = Decision::withoutGlobalScopes()
            ->where('ai_evaluation_id', $eval->id)
            ->exists();

        if ($existing) {
            return;
        }

        $evaluateDecisionRules->execute($eval);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('RunDecisionEngineJob failed', [
            'ai_evaluation_id' => $this->aiEvaluationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
