<?php

namespace App\Domains\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted whenever a super-admin mutates a tenant's subscription (plan change,
 * status transition, trial extension). Carries the team id and a short action
 * key so downstream listeners (billing sync, notifications) can react.
 */
class TenantSubscriptionChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $teamId,
        public string $action,
        public ?string $planCode = null,
    ) {}
}
