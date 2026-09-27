<?php

namespace App\Domains\Assets;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Commands\RecordAssetUsageMeters;
use App\Domains\Assets\Listeners\PollLocationsOnIntegrationConnected;
use App\Domains\Assets\Listeners\RecordTelemetryOnEventNormalized;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Services\AssetSyncHandlerService;
use App\Domains\Integrations\Events\IntegrationConnected;
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
            ]);
        }

        // Pull an initial set of asset positions right after an integration connects.
        Event::listen(IntegrationConnected::class, PollLocationsOnIntegrationConnected::class);

        // Record any telemetry the event itself measured (speeding peak).
        Event::listen(EventNormalized::class, RecordTelemetryOnEventNormalized::class);

        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('assets:record-usage-meters')->daily();
        });
    }
}
