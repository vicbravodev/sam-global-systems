<?php

namespace App\Domains\Tenancy;

use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Actions\RegisterUsageEvent;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordUsageEvent::class);
        $this->app->singleton(RegisterUsageEvent::class);
    }

    public function boot(): void
    {
        //
    }
}
