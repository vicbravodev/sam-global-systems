<?php

namespace App\Domains\Decisions\Events;

use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\EscalationPolicy;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class EscalationTriggered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Decision $decision,
        public readonly EscalationPolicy $policy,
    ) {}
}
