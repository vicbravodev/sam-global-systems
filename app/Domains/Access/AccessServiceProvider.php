<?php

namespace App\Domains\Access;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthorizeAction::class);
    }

    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
    }
}
