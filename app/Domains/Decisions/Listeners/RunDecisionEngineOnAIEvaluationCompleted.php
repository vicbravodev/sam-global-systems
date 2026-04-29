<?php

namespace App\Domains\Decisions\Listeners;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\Decisions\Jobs\RunDecisionEngineJob;

class RunDecisionEngineOnAIEvaluationCompleted
{
    public function handle(AIEvaluationCompleted $event): void
    {
        RunDecisionEngineJob::dispatch($event->evaluation->id);
    }
}
