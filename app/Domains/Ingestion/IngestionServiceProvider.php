<?php

namespace App\Domains\Ingestion;

use App\Contracts\NullImplementations\NullObjectStorage;
use App\Contracts\ObjectStorage;
use App\Contracts\RawEventIngestion;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Policies\RawEventPolicy;
use App\Domains\Ingestion\Services\RawEventIngestionService;
use App\Infrastructure\Storage\RustFsObjectStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RawEventIngestion::class, RawEventIngestionService::class);

        $this->app->singletonIf(ObjectStorage::class, function () {
            // La config de un disco es un array (o null si no existe): sólo
            // un array no vacío equivale al truthy anterior.
            $disk = config('filesystems.disks.rustfs');

            if (is_array($disk) && $disk !== []) {
                return new RustFsObjectStorage;
            }

            return new NullObjectStorage;
        });
    }

    public function boot(): void
    {
        Gate::policy(RawEvent::class, RawEventPolicy::class);
    }
}
