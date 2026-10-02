<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\TenantSubscriptionChanged;
use App\Domains\Tenancy\Models\Subscription;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Transitions a subscription to a new status. Suspended/Canceled cut operational
 * access (the status enum's grantsOperationalAccess() drives the gates), while
 * reactivating clears the end date.
 */
class UpdateSubscriptionStatus
{
    public function execute(Subscription $subscription, SubscriptionStatus $status): Subscription
    {
        $previous = $subscription->status;
        $subscription->status = $status;

        if ($status === SubscriptionStatus::Canceled) {
            $subscription->cancel_at_period_end = true;
            $subscription->ends_at = $subscription->ends_at ?? now();
        }

        if ($status->grantsOperationalAccess()) {
            // Reactivating (active/past_due) clears any pending cancellation.
            $subscription->cancel_at_period_end = false;
            $subscription->ends_at = null;
        }

        $subscription->save();

        // Suspender o reactivar decide si el tenant opera: queda narrado con
        // el estado anterior y lo que implica para el acceso.
        TenantContext::for($subscription->team_id, fn () => SystemLog::ok('tenancy.subscription.status_changed', input: [
            'team_id' => $subscription->team_id,
            'subscription_id' => $subscription->id,
            'actor_id' => auth()->id(),
        ], result: [
            'previous_status' => $previous?->value,
            'status' => $status->value,
            'grants_operational_access' => $status->grantsOperationalAccess(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
        ]));

        TenantSubscriptionChanged::dispatch(
            $subscription->team_id,
            'status_changed:'.$status->value,
        );

        return $subscription;
    }
}
