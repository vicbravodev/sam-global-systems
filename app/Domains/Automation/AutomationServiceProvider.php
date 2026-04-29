<?php

namespace App\Domains\Automation;

use App\Contracts\NullImplementations\NullTenantAutomationPoliciesResolver;
use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SPEC-16-DEFERRED: TenantConfig domain (spec 16) will provide the real
        // resolver bound to per-tenant automation policies. Until then, sane defaults.
        $this->app->singletonIf(
            TenantAutomationPoliciesResolver::class,
            NullTenantAutomationPoliciesResolver::class,
        );
    }

    public function boot(): void
    {
        Gate::policy(AutomationWorkflow::class, AutomationWorkflowPolicy::class);
        Gate::policy(ActionExecution::class, ActionExecutionPolicy::class);
        Gate::policy(ActionTemplate::class, ActionTemplatePolicy::class);

        Event::listen(ActionExecuted::class, BroadcastActionExecuted::class);
        Event::listen(ActionFailed::class, BroadcastActionFailed::class);

        // SPEC-10-DEFERRED: Decisions domain ships `App\Domains\Decisions\Events\DecisionMade`.
        Event::listen(
            'App\\Domains\\Decisions\\Events\\DecisionMade',
            [TriggerAutomationOnDecisionMade::class, 'handle'],
        );

        // SPEC-11-DEFERRED: Incidents domain ships `IncidentCreated` and `IncidentStatusChanged`.
        Event::listen(
            'App\\Domains\\Incidents\\Events\\IncidentCreated',
            [TriggerAutomationOnIncidentCreated::class, 'handle'],
        );

        Event::listen(
            'App\\Domains\\Incidents\\Events\\IncidentStatusChanged',
            [TriggerAutomationOnIncidentEscalated::class, 'handle'],
        );

        Event::listen(
            'App\\Domains\\Incidents\\Events\\IncidentEscalated',
            [TriggerAutomationOnIncidentEscalated::class, 'handle'],
        );
    }
}
