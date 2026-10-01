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
use App\Support\TenantContext;
use LogicException;

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

        // Todo lo que cuelga del tenant recién creado (suscripción, features,
        // branding y los listeners de TenantCreated) corre DENTRO de él: el
        // caller es la consola de super-admin, cuyo usuario tiene otro team
        // actual que el scope global aplicaría. Ver CLAUDE.md §2.1.
        TenantContext::for($team->id, function () use ($team, $owner, $planCode) {
            if ($planCode) {
                $plan = Plan::where('code', $planCode)->firstOrFail();

                Subscription::query()->create([
                    'team_id' => $team->id,
                    'plan_id' => $plan->id,
                    // Sin periodo de prueba (decisión 2026-09-28): el tenant
                    // nace activo y se factura por tracto-día desde el alta.
                    'status' => SubscriptionStatus::Active,
                    'billing_cycle' => $plan->billing_cycle ?? BillingCycle::Monthly,
                    'starts_at' => now(),
                ]);

                $this->seedDefaultFeatures($team, $plan);
            }

            TenantBranding::query()->create([
                'team_id' => $team->id,
            ]);

            TenantCreated::dispatch($team, $owner);
        });

        return $team;
    }

    private function seedDefaultFeatures(Team $team, Plan $plan): void
    {
        $billingRates = BillingRate::where('plan_id', $plan->id)->with('usageMeter')->get();

        foreach ($billingRates as $rate) {
            // usage_meter_id es FK NOT NULL con cascade y UsageMeter no usa
            // soft-delete: la tarifa siempre tiene su medidor.
            $meter = $rate->usageMeter ?? throw new LogicException("billing_rate {$rate->id} sin usage_meter");

            TenantFeature::query()->create([
                'team_id' => $team->id,
                'feature_key' => $meter->code,
                'enabled' => true,
                'source' => FeatureSource::DefaultPlan,
                'limits_json' => $rate->included_quantity > 0
                    ? ['included_quantity' => $rate->included_quantity]
                    : null,
            ]);
        }
    }
}
