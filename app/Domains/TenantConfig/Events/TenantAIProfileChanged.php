<?php

namespace App\Domains\TenantConfig\Events;

use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantAIProfileChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $teamId,
        public AutomationLevel $automationLevel,
        public RiskTolerance $riskTolerance,
        public MediaStrategy $mediaStrategy,
    ) {}
}
