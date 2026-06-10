<?php

namespace App\Domains\Tenancy;

use App\Domains\Tenancy\Actions\ChangeTenantPlan;
use App\Domains\Tenancy\Actions\DeleteTenant;
use App\Domains\Tenancy\Actions\ExtendTrial;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Actions\RegisterUsageEvent;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Domains\Tenancy\Actions\SetTenantFeature;
use App\Domains\Tenancy\Actions\UpdatePlanLimits;
use App\Domains\Tenancy\Actions\UpdateSubscriptionStatus;
use App\Domains\Tenancy\Actions\UpdateTenant;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Policies\SubscriptionPolicy;
use App\Domains\Tenancy\Policies\TenantBrandingPolicy;
use App\Domains\Tenancy\Policies\TenantFeaturePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordUsageEvent::class);
        $this->app->singleton(RegisterUsageEvent::class);
        $this->app->singleton(ChangeTenantPlan::class);
        $this->app->singleton(UpdateSubscriptionStatus::class);
        $this->app->singleton(ExtendTrial::class);
        $this->app->singleton(ResolveAssetLimit::class);
        $this->app->singleton(UpdatePlanLimits::class);
        $this->app->singleton(SetTenantFeature::class);
        $this->app->singleton(SetGlobalRole::class);
        $this->app->singleton(UpdateTenant::class);
        $this->app->singleton(DeleteTenant::class);
    }

    public function boot(): void
    {
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(TenantBranding::class, TenantBrandingPolicy::class);
        Gate::policy(TenantFeature::class, TenantFeaturePolicy::class);
    }
}
