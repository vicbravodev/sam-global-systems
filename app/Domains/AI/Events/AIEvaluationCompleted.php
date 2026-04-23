<?php

namespace App\Domains\AI\Events;

use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AIEvaluationCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public AIEventEvaluation $evaluation,
    ) {}
}
