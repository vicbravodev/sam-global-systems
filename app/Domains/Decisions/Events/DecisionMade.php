<?php

namespace App\Domains\Decisions\Events;

use App\Domains\Decisions\Models\Decision;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DecisionMade
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Decision $decision,
    ) {}
}
