<?php

namespace App\Domains\Drivers;

use App\Contracts\DriverSyncHandler;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Policies\DriverPolicy;
use App\Domains\Drivers\Services\DriverSyncHandlerService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DriversServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DriverSyncHandler::class, DriverSyncHandlerService::class);
    }

    public function boot(): void
    {
        Gate::policy(Driver::class, DriverPolicy::class);
    }
}
