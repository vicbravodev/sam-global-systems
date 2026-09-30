<?php

namespace App\Domains\Incidents;

use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Listeners\AnnotateIncidentOnMediaAssessmentCompleted;
use App\Domains\Incidents\Listeners\ApplyExternalResolutionOnEventNormalized;
use App\Domains\Incidents\Listeners\ApplyReevaluationOnDecisionMade;
use App\Domains\Incidents\Listeners\AssignOnCallOnIncidentCreated;
use App\Domains\Incidents\Listeners\CreateIncidentOnDecisionMade;
use App\Domains\Incidents\Listeners\OpenEmergencyIncidentOnEventNormalized;
use App\Domains\Incidents\Listeners\RefreshIncidentOnEventMediaAvailable;
use App\Domains\Incidents\Listeners\StartCallVerificationOnIncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Policies\IncidentPolicy;
use App\Domains\Incidents\Queries\DbIncidentMetricsQuery;
use App\Domains\Normalization\Events\EventNormalized;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IncidentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(IncidentMetricsQuery::class, DbIncidentMetricsQuery::class);
    }

    public function boot(): void
    {
        Gate::policy(Incident::class, IncidentPolicy::class);

        Event::listen(DecisionMade::class, CreateIncidentOnDecisionMade::class);
        // Reevaluaciones (v2+): actualizan el incidente del evento, no duplican.
        Event::listen(DecisionMade::class, ApplyReevaluationOnDecisionMade::class);
        Event::listen(EventNormalized::class, ApplyExternalResolutionOnEventNormalized::class);
        // Carril rápido: una emergencia abre su incidente crítico antes de la IA.
        Event::listen(EventNormalized::class, OpenEmergencyIncidentOnEventNormalized::class);
        Event::listen(IncidentCreated::class, AssignOnCallOnIncidentCreated::class);
        Event::listen(IncidentCreated::class, StartCallVerificationOnIncidentCreated::class);
        Event::listen(MediaAssessmentCompleted::class, AnnotateIncidentOnMediaAssessmentCompleted::class);
        Event::listen(EventMediaAvailable::class, RefreshIncidentOnEventMediaAvailable::class);
    }
}
