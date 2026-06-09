<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\TenantSubscriptionChanged;
use App\Domains\Tenancy\Models\Subscription;

/**
 * Extends (or starts) a subscription trial by N days from the later of now and
 * the current trial end, and ensures the subscription reads as trialing.
 */
class ExtendTrial
{
    public function execute(Subscription $subscription, int $days): Subscription
    {
        $current = $subscription->trial_ends_at;

        $base = $current !== null && $current->isFuture()
            ? $current
            : now();

        $subscription->trial_ends_at = $base->copy()->addDays($days);

        if (! $subscription->status->grantsOperationalAccess()) {
            $subscription->status = SubscriptionStatus::Trialing;
        }

        $subscription->save();

        TenantSubscriptionChanged::dispatch((int) $subscription->team_id, 'trial_extended');

        return $subscription;
    }
}
