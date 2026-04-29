<?php

namespace App\Domains\Automation\Events;

use App\Domains\Automation\Enums\EscalationStepType;
use App\Domains\Automation\Models\WorkflowExecution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EscalationStepExecuted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $escalationStepId,
        public readonly EscalationStepType $stepType,
        public readonly WorkflowExecution $workflowExecution,
    ) {}
}
