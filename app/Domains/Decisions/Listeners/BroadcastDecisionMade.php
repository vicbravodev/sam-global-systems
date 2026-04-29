<?php

namespace App\Domains\Decisions\Listeners;

use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Support\DecisionMadeBroadcast;

class BroadcastDecisionMade
{
    public function handle(DecisionMade $event): void
    {
        broadcast(DecisionMadeBroadcast::fromModel($event->decision));
    }
}
