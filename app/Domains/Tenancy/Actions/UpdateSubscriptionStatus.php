<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\TenantSubscriptionChanged;
use App\Domains\Tenancy\Models\Subscription;

/**
 * Transitions a subscription to a new status. Suspended/Canceled cut operational
 * access (the status enum's grantsOperationalAccess() drives the gates), while
 * reactivating clears the end date.
 */
class UpdateSubscriptionStatus
{
    public function execute(Subscription $subscription, SubscriptionStatus $status): Subscription
    {
        $subscription->status = $status;

        if ($status === SubscriptionStatus::Canceled) {
            $subscription->cancel_at_period_end = true;
            $subscription->ends_at = $subscription->ends_at ?? now();
        }

        if ($status->grantsOperationalAccess()) {
            // Reactivating (active/trialing/past_due) clears any pending cancellation.
            $subscription->cancel_at_period_end = false;
            $subscription->ends_at = null;
        }

        $subscription->save();

        TenantSubscriptionChanged::dispatch(
            (int) $subscription->team_id,
            'status_changed:'.$status->value,
        );

        return $subscription;
    }
}
