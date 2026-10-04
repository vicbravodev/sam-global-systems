<?php

namespace App\Domains\Drivers;

use App\Contracts\DriverSyncHandler;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Policies\DriverPolicy;
use App\Domains\Drivers\Policies\HosMonitoringPolicy;
use App\Domains\Drivers\Services\DriverSyncHandlerService;
use App\Models\Team;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class DriversServiceProvider extends ServiceProvider
{
    /** Peticiones por minuto y tenant a las etiquetas + vista previa HOS. */
    public const int HOS_PREVIEW_PER_MINUTE = 30;

    public function register(): void
    {
        $this->app->singleton(DriverSyncHandler::class, DriverSyncHandlerService::class);
    }

    public function boot(): void
    {
        Gate::policy(Driver::class, DriverPolicy::class);
        Gate::policy(HosDriverState::class, HosMonitoringPolicy::class);

        // Selector de etiquetas y vista previa HOS: con caché fría pueden leer
        // Samsara, cuya cuota es por organización. Tope por tenant.
        RateLimiter::for('hos-preview', function (Request $request): Limit {
            $team = $request->route('current_team');
            $key = $team instanceof Team ? (string) $team->id : (is_string($team) ? $team : $request->ip());

            return Limit::perMinute(self::HOS_PREVIEW_PER_MINUTE)->by('hos-preview:'.$key);
        });
    }
}
