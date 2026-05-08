<?php

namespace App\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Listeners\BroadcastAIEvaluationCompleted;
use App\Domains\AI\Listeners\EvaluateMediaOnEventMediaAvailable;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Policies\AIEvaluationPolicy;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Events\EventMediaAvailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SPEC-09-SDK-DEFERRED: real Laravel AI SDK integration lands in PR #2b;
        // until then we bind a deterministic Null implementation.
        $this->app->singletonIf(EventEvaluationAgent::class, NullEventEvaluationAgent::class);

        // SPEC-09-MULTIMODAL-DEFERRED: real multimodal SDK agent lands in PR #2b.
        // The Null implementation produces deterministic outputs so the rest of
        // the multimodal pipeline (persistence, usage metering, mode promotion)
        // is exercisable end-to-end in tests and stub deployments.
        $this->app->singletonIf(MediaAssessmentAgent::class, NullMediaAssessmentAgent::class);
    }

    public function boot(): void
    {
        Gate::policy(AIEventEvaluation::class, AIEvaluationPolicy::class);

        Event::listen(EventContextBuilt::class, EvaluateOnEventContextBuilt::class);
        Event::listen(EventMediaAvailable::class, EvaluateMediaOnEventMediaAvailable::class);
        Event::listen(AIEvaluationCompleted::class, BroadcastAIEvaluationCompleted::class);
    }
}
