<?php

namespace App\Domains\Context;

use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Listeners\EnrichContextOnEventNormalized;
use App\Domains\Context\Listeners\ExtractMediaOnContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\Geofence;
use App\Domains\Context\Policies\EventContextPolicy;
use App\Domains\Context\Policies\EventMediaContextPolicy;
use App\Domains\Context\Policies\GeofencePolicy;
use App\Domains\Normalization\Events\EventNormalized;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ContextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(EventContextSnapshot::class, EventContextPolicy::class);
        Gate::policy(Geofence::class, GeofencePolicy::class);
        Gate::policy(EventMediaContext::class, EventMediaContextPolicy::class);

        Event::listen(EventNormalized::class, EnrichContextOnEventNormalized::class);
        Event::listen(EventContextBuilt::class, ExtractMediaOnContextBuilt::class);

        Geofence::saved(fn (Geofence $geofence) => ResolveGeofenceContext::invalidateCacheForTeam($geofence->team_id));
        Geofence::deleted(fn (Geofence $geofence) => ResolveGeofenceContext::invalidateCacheForTeam($geofence->team_id));
    }
}
