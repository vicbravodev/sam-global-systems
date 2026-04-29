<?php

namespace App\Domains\Decisions\Jobs;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReevaluateDecisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $aiEvaluationId,
    ) {
        $this->onQueue('decisions');
    }

    public function handle(EvaluateDecisionRules $evaluateDecisionRules): void
    {
        $eval = AIEventEvaluation::withoutGlobalScopes()->find($this->aiEvaluationId);

        if ($eval === null) {
            return;
        }

        $evaluateDecisionRules->execute($eval);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ReevaluateDecisionJob failed', [
            'ai_evaluation_id' => $this->aiEvaluationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
