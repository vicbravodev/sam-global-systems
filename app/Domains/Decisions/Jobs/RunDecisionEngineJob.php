<?php

namespace App\Domains\Decisions\Jobs;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Models\Decision;
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
            SystemLog::skipped('decisions.engine.skipped', reason: 'evaluation_missing', input: [
                'ai_evaluation_id' => $this->aiEvaluationId,
                'stage' => 'engine_job',
            ]);

            return;
        }

        PipelineTrace::adopt(null, $eval->team_id, [
            'normalized_event_id' => $eval->normalized_event_id,
            'ai_evaluation_id' => $eval->id,
        ]);

        // Entra en el tenant de la evaluación: el motor lee las reglas y la
        // política de escalación del tenant. Ver §2.1.
        TenantContext::for($eval->team_id, function () use ($eval, $evaluateDecisionRules) {
            $existingId = Decision::query()
                ->where('ai_evaluation_id', $eval->id)
                ->value('id');

            if ($existingId !== null) {
                SystemLog::skipped('decisions.decision.already_exists', reason: 'decision_exists', input: [
                    'ai_evaluation_id' => $eval->id,
                    'stage' => 'engine_job',
                ], result: ['decision_id' => $existingId]);

                return;
            }

            $evaluateDecisionRules->execute($eval);
        });
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'ai_evaluation_id' => $this->aiEvaluationId,
        ]);
    }
}
