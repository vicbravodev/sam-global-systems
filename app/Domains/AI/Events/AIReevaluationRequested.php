<?php

namespace App\Domains\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AIReevaluationRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $normalizedEventId,
        public string $triggerType,
        public ?int $triggerReferenceId = null,
    ) {}
}
