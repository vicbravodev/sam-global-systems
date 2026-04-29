<?php

namespace App\Domains\Incidents;

use App\Domains\Incidents\Listeners\CreateIncidentOnDecisionMade;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Policies\IncidentPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IncidentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Incident::class, IncidentPolicy::class);

        // SPEC-10-DEFERRED: listener registered by string until the Decisions domain
        // (`App\Domains\Decisions\Events\DecisionMade`) is merged. While the class does
        // not exist Laravel will simply never fire the listener — it does not crash at boot.
        Event::listen(
            'App\\Domains\\Decisions\\Events\\DecisionMade',
            [CreateIncidentOnDecisionMade::class, 'handle'],
        );
    }
}
