<?php

namespace App\Domains\Integrations;

use App\Contracts\AssetSyncHandler;
use App\Contracts\DriverSyncHandler;
use App\Contracts\NullImplementations\NullAssetSyncHandler;
use App\Contracts\NullImplementations\NullDriverSyncHandler;
use App\Contracts\NullImplementations\NullRawEventIngestion;
use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Adapters\ProviderAdapterManager;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Listeners\SyncCatalogOnIntegrationConnected;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Policies\TenantIntegrationPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(RawEventIngestion::class, NullRawEventIngestion::class);
        $this->app->singletonIf(AssetSyncHandler::class, NullAssetSyncHandler::class);
        $this->app->singletonIf(DriverSyncHandler::class, NullDriverSyncHandler::class);
        $this->app->singletonIf(ProviderAdapter::class, ProviderAdapterManager::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantIntegration::class, TenantIntegrationPolicy::class);

        // Backfill the asset/driver catalog as soon as an integration connects.
        Event::listen(IntegrationConnected::class, SyncCatalogOnIntegrationConnected::class);
    }
}
