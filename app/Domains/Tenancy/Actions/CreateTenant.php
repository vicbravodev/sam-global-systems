<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\TenantCreated;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class CreateTenant
{
    public function execute(
        string $name,
        User $owner,
        ?string $planCode = null,
    ): Team {
        $team = Team::create([
            'name' => $name,
            'is_personal' => false,
        ]);

        $team->members()->attach($owner, [
            'role' => TeamRole::Owner->value,
        ]);

        if ($planCode) {
            $plan = Plan::where('code', $planCode)->firstOrFail();

            Subscription::withoutGlobalScopes()->create([
                'team_id' => $team->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing,
                'billing_cycle' => $plan->billing_cycle ?? BillingCycle::Monthly,
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays(14),
            ]);

            $this->seedDefaultFeatures($team, $plan);
        }

        TenantBranding::withoutGlobalScopes()->create([
            'team_id' => $team->id,
        ]);

        TenantCreated::dispatch($team, $owner);

        return $team;
    }

    private function seedDefaultFeatures(Team $team, Plan $plan): void
    {
        $billingRates = BillingRate::where('plan_id', $plan->id)->get();

        foreach ($billingRates as $rate) {
            TenantFeature::withoutGlobalScopes()->create([
                'team_id' => $team->id,
                'feature_key' => $rate->usageMeter->code,
                'enabled' => true,
                'source' => FeatureSource::DefaultPlan,
                'limits_json' => $rate->included_quantity > 0
                    ? ['included_quantity' => $rate->included_quantity]
                    : null,
            ]);
        }
    }
}
