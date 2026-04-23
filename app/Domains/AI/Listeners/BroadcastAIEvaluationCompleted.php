<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Support\AIEvaluationCompletedBroadcast;

class BroadcastAIEvaluationCompleted
{
    public function handle(AIEvaluationCompleted $event): void
    {
        broadcast(AIEvaluationCompletedBroadcast::fromModel($event->evaluation));
    }
}
