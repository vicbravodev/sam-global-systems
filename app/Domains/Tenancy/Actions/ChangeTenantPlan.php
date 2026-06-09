<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\TenantSubscriptionChanged;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Reassigns a tenant's plan and reconciles its feature allowances from the new
 * plan's billing rates. Manual feature overrides are preserved — only
 * plan-derived features are re-seeded.
 */
class ChangeTenantPlan
{
    public function execute(Team $team, string $planCode): Subscription
    {
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        return DB::transaction(function () use ($team, $plan) {
            $subscription = Subscription::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->orderByDesc('starts_at')
                ->first();

            if ($subscription === null) {
                $subscription = new Subscription([
                    'team_id' => $team->id,
                    'status' => SubscriptionStatus::Active,
                    'billing_cycle' => $plan->billing_cycle ?? BillingCycle::Monthly,
                    'starts_at' => now(),
                ]);
            }

            $subscription->plan_id = $plan->id;
            $subscription->save();

            $this->reconcileFeatures($team, $plan);

            TenantSubscriptionChanged::dispatch($team->id, 'plan_changed', $plan->code);

            return $subscription->load('plan');
        });
    }

    /**
     * Re-seed plan-derived features without clobbering manual overrides.
     */
    private function reconcileFeatures(Team $team, Plan $plan): void
    {
        $billingRates = BillingRate::query()
            ->with('usageMeter')
            ->where('plan_id', $plan->id)
            ->get();

        foreach ($billingRates as $rate) {
            $featureKey = $rate->usageMeter?->code;

            if ($featureKey === null) {
                continue;
            }

            $existing = TenantFeature::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('feature_key', $featureKey)
                ->first();

            if ($existing && $existing->source === FeatureSource::ManualOverride) {
                continue;
            }

            TenantFeature::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'feature_key' => $featureKey],
                [
                    'enabled' => true,
                    'source' => FeatureSource::DefaultPlan,
                    'limits_json' => $rate->included_quantity > 0
                        ? ['included_quantity' => $rate->included_quantity]
                        : null,
                ],
            );
        }
    }
}
