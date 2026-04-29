<?php

namespace App\Domains\Incidents;

use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Listeners\CreateIncidentOnDecisionMade;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Policies\IncidentPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IncidentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Incident::class, IncidentPolicy::class);

        Event::listen(DecisionMade::class, CreateIncidentOnDecisionMade::class);
    }
}
