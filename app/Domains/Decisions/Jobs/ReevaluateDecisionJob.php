<?php

namespace App\Domains\Decisions\Jobs;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Support\JobFailureReporter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        // Entra en el tenant de la evaluación antes de re-decidir. Ver §2.1.
        TenantContext::for($eval->team_id, fn () => $evaluateDecisionRules->execute($eval));
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'ai_evaluation_id' => $this->aiEvaluationId,
        ]);
    }
}
