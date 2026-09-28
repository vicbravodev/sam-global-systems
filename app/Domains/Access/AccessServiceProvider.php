<?php

namespace App\Domains\Access;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Policies\RolePolicy;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: it memoizes the team's subscription and
        // features for the current request / queued job only.
        $this->app->scoped(AuthorizeAction::class);
    }

    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        $forget = function (Subscription|TenantFeature $model): void {
            if ($this->app->resolved(AuthorizeAction::class) && $model->team_id !== null) {
                $this->app->make(AuthorizeAction::class)->forgetTeamAccess((int) $model->team_id);
            }
        };

        foreach ([Subscription::class, TenantFeature::class] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }
}
