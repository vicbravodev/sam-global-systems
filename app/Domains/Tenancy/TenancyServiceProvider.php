<?php

namespace App\Domains\Tenancy;

use App\Domains\Tenancy\Actions\ChangeTenantPlan;
use App\Domains\Tenancy\Actions\ExtendTrial;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Actions\RegisterUsageEvent;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\SetTenantFeature;
use App\Domains\Tenancy\Actions\UpdatePlanLimits;
use App\Domains\Tenancy\Actions\UpdateSubscriptionStatus;
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
    }

    public function boot(): void
    {
        //
    }
}
