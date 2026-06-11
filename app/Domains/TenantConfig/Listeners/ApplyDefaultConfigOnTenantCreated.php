<?php

namespace App\Domains\TenantConfig\Listeners;

use App\Domains\Tenancy\Events\TenantCreated;
use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;

/**
 * Roadmap V2-A5: every tenant is born with the SAM-tuned monitoring protocol
 * (settings, panic rules, escalation ladder). The action is idempotent and
 * never overwrites existing config, so a replayed event is harmless.
 */
class ApplyDefaultConfigOnTenantCreated
{
    public function __construct(
        private readonly ApplyDefaultTenantConfig $applyDefaultConfig,
    ) {}

    public function handle(TenantCreated $event): void
    {
        $this->applyDefaultConfig->execute($event->team);
    }
}
