<?php

namespace App\Domains\Normalization;

use App\Contracts\Normalization\NormalizedEventStatsQuery;
use App\Domains\Ingestion\Events\RawEventProcessed;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Domains\Normalization\Listeners\AlertOnUnmappedProviderAlert;
use App\Domains\Normalization\Listeners\CorrelateSafetyAlertEchoOnEventNormalized;
use App\Domains\Normalization\Listeners\NormalizeOnRawEventProcessed;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Normalization\Policies\EventMappingRulePolicy;
use App\Domains\Normalization\Policies\NormalizedEventPolicy;
use App\Domains\Normalization\Queries\DbNormalizedEventStatsQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class NormalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(NormalizedEventStatsQuery::class, DbNormalizedEventStatsQuery::class);
    }

    public function boot(): void
    {
        Gate::policy(NormalizedEvent::class, NormalizedEventPolicy::class);
        Gate::policy(EventMappingRule::class, EventMappingRulePolicy::class);

        Event::listen(RawEventProcessed::class, NormalizeOnRawEventProcessed::class);
        Event::listen(EventUnmapped::class, AlertOnUnmappedProviderAlert::class);
        Event::listen(EventNormalized::class, CorrelateSafetyAlertEchoOnEventNormalized::class);
    }
}
