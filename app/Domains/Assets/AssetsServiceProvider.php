<?php

namespace App\Domains\Assets;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Commands\RecordAssetUsageMeters;
use App\Domains\Assets\Commands\ShowTelematicsStatus;
use App\Domains\Assets\Listeners\FreezeLocationTrailOnIncidentCreated;
use App\Domains\Assets\Listeners\NotifyPendingAssetsOnSyncCompleted;
use App\Domains\Assets\Listeners\RecordTelemetryOnEventNormalized;
use App\Domains\Assets\Listeners\ResumeTelematicsFeedOnIntegrationActivated;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Services\AssetSyncHandlerService;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Events\IntegrationSyncCompleted;
use App\Domains\Normalization\Events\EventNormalized;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AssetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssetSyncHandler::class, AssetSyncHandlerService::class);
    }

    public function boot(): void
    {
        Gate::policy(Asset::class, AssetPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RecordAssetUsageMeters::class,
                ShowTelematicsStatus::class,
            ]);
        }

        // A re-activated integration (credentials fixed) resumes its telematics
        // feed without leftover backoff.
        Event::listen(IntegrationStatusChanged::class, ResumeTelematicsFeedOnIntegrationActivated::class);

        // New units found by a sync start pending: tell the tenant admin.
        Event::listen(IntegrationSyncCompleted::class, NotifyPendingAssetsOnSyncCompleted::class);

        // Freeze the GPS trail around each incident before retention purges it.
        Event::listen(IncidentCreated::class, FreezeLocationTrailOnIncidentCreated::class);

        // Record any telemetry the event itself measured (speeding peak).
        Event::listen(EventNormalized::class, RecordTelemetryOnEventNormalized::class);

        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            // In background so the every-5-s telematics tick isn't held behind it.
            // Cierre diario en hora del cliente (no 00:00 UTC = 18:00 de México):
            // al arrancar cada día local, cada unidad vigilada suma su tracto-día.
            $schedule->command('assets:record-usage-meters')
                ->dailyAt('00:05')
                ->timezone((string) config('billing.timezone', 'America/Mexico_City'))
                ->onOneServer()
                ->runInBackground();
        });
    }
}
