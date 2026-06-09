<?php

namespace App\Domains\Assets;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Commands\RecordAssetUsageMeters;
use App\Domains\Assets\Listeners\PollLocationsOnIntegrationConnected;
use App\Domains\Assets\Services\AssetSyncHandlerService;
use App\Domains\Integrations\Events\IntegrationConnected;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AssetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssetSyncHandler::class, AssetSyncHandlerService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RecordAssetUsageMeters::class,
            ]);
        }

        // Pull an initial set of asset positions right after an integration connects.
        Event::listen(IntegrationConnected::class, PollLocationsOnIntegrationConnected::class);

        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('assets:record-usage-meters')->daily();
        });
    }
}
