<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Support\ActionExecutedBroadcast;

class BroadcastActionExecuted
{
    public function handle(ActionExecuted $event): void
    {
        broadcast(ActionExecutedBroadcast::fromModel($event->execution));
    }
}
