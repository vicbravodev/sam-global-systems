<?php

namespace App\Domains\Drivers;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Policies\DriverPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DriversServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Driver::class, DriverPolicy::class);
    }
}
