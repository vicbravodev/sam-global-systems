<?php

namespace App\Domains\Access;

use App\Domains\Access\Actions\AuthorizeAction;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthorizeAction::class);
    }

    public function boot(): void
    {
        //
    }
}
