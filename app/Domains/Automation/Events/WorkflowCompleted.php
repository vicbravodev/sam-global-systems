<?php

namespace App\Domains\Automation\Events;

use App\Domains\Automation\Models\WorkflowExecution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkflowExecution $execution,
    ) {}
}
