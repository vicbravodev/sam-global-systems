<?php

namespace App\Domains\Automation\Listeners;

use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Support\ActionExecutedBroadcast;

class BroadcastActionFailed
{
    public function handle(ActionFailed $event): void
    {
        broadcast(ActionExecutedBroadcast::fromModel($event->execution));
    }
}
