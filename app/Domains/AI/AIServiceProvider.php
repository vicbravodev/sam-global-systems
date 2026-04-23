<?php

namespace App\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Listeners\BroadcastAIEvaluationCompleted;
use App\Domains\AI\Listeners\EvaluateEventOnEventNormalized;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Policies\AIEvaluationPolicy;
use App\Domains\Normalization\Events\EventNormalized;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SPEC-09-SDK-DEFERRED: real Laravel AI SDK integration lands in PR #2;
        // until then we bind a deterministic Null implementation.
        $this->app->singletonIf(EventEvaluationAgent::class, NullEventEvaluationAgent::class);
    }

    public function boot(): void
    {
        Gate::policy(AIEventEvaluation::class, AIEvaluationPolicy::class);

        Event::listen(EventNormalized::class, EvaluateEventOnEventNormalized::class);
        Event::listen(AIEvaluationCompleted::class, BroadcastAIEvaluationCompleted::class);
    }
}
