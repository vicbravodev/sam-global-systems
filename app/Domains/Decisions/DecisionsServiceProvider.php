<?php

namespace App\Domains\Decisions;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Listeners\BroadcastDecisionMade;
use App\Domains\Decisions\Listeners\RunDecisionEngineOnAIEvaluationCompleted;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Domains\Decisions\Policies\DecisionPolicy;
use App\Domains\Decisions\Policies\DecisionRulePolicy;
use App\Domains\Decisions\Policies\EscalationPolicyPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DecisionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Decision::class, DecisionPolicy::class);
        Gate::policy(DecisionRule::class, DecisionRulePolicy::class);
        Gate::policy(EscalationPolicy::class, EscalationPolicyPolicy::class);

        Event::listen(AIEvaluationCompleted::class, RunDecisionEngineOnAIEvaluationCompleted::class);
        Event::listen(DecisionMade::class, BroadcastDecisionMade::class);
    }
}
