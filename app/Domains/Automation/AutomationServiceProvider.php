<?php

namespace App\Domains\Automation;

use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Listeners\BroadcastActionExecuted;
use App\Domains\Automation\Listeners\BroadcastActionFailed;
use App\Domains\Automation\Listeners\TriggerAutomationOnDecisionMade;
use App\Domains\Automation\Listeners\TriggerAutomationOnIncidentCreated;
use App\Domains\Automation\Listeners\TriggerAutomationOnIncidentEscalated;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Policies\ActionExecutionPolicy;
use App\Domains\Automation\Policies\ActionTemplatePolicy;
use App\Domains\Automation\Policies\AutomationWorkflowPolicy;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(AutomationWorkflow::class, AutomationWorkflowPolicy::class);
        Gate::policy(ActionExecution::class, ActionExecutionPolicy::class);
        Gate::policy(ActionTemplate::class, ActionTemplatePolicy::class);

        Event::listen(ActionExecuted::class, BroadcastActionExecuted::class);
        Event::listen(ActionFailed::class, BroadcastActionFailed::class);

        Event::listen(DecisionMade::class, TriggerAutomationOnDecisionMade::class);
        Event::listen(IncidentCreated::class, TriggerAutomationOnIncidentCreated::class);
        Event::listen(IncidentStatusChanged::class, TriggerAutomationOnIncidentEscalated::class);
    }
}
