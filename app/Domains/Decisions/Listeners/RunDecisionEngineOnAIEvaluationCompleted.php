<?php

namespace App\Domains\Decisions\Listeners;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\Decisions\Jobs\RunDecisionEngineJob;

class RunDecisionEngineOnAIEvaluationCompleted
{
    public function handle(AIEvaluationCompleted $event): void
    {
        // AIEvaluationCompleted se emite dentro de la transacción de
        // EvaluateEventWithAI: si el worker arranca antes del commit no encuentra
        // la evaluación y el evento se queda sin decisión ni incidente.
        RunDecisionEngineJob::dispatch($event->evaluation->id)->afterCommit();
    }
}
