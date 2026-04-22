<?php

namespace App\Domains\Ingestion;

use App\Contracts\NullImplementations\NullObjectStorage;
use App\Contracts\ObjectStorage;
use App\Contracts\RawEventIngestion;
use App\Domains\Ingestion\Services\RawEventIngestionService;
use App\Infrastructure\Storage\RustFsObjectStorage;
use Illuminate\Support\ServiceProvider;

class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RawEventIngestion::class, RawEventIngestionService::class);

        $this->app->singletonIf(ObjectStorage::class, function () {
            if (config('filesystems.disks.rustfs')) {
                return new RustFsObjectStorage;
            }

            return new NullObjectStorage;
        });
    }

    public function boot(): void
    {
        //
    }
}
