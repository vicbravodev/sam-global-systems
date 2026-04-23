<?php

namespace App\Domains\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AIReevaluationRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $normalizedEventId,
        public readonly string $triggerType,
        public readonly ?int $triggerReferenceId = null,
    ) {}
}
