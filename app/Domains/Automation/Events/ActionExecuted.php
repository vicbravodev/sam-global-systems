<?php

namespace App\Domains\Automation\Events;

use App\Domains\Automation\Models\ActionExecution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActionExecuted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ActionExecution $execution,
    ) {}
}
