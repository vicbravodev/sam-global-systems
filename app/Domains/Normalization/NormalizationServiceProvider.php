<?php

namespace App\Domains\Normalization;

use App\Domains\Ingestion\Events\RawEventProcessed;
use App\Domains\Normalization\Listeners\NormalizeOnRawEventProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NormalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(RawEventProcessed::class, NormalizeOnRawEventProcessed::class);
    }
}
