<?php

namespace App\Domains\Decisions\Events;

use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DecisionOverridden
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DecisionOverride $override,
        public readonly Decision $decision,
    ) {}
}
