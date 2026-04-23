<?php

namespace App\Domains\AI;

use App\Contracts\AiProviderAdapter;
use App\Contracts\NullImplementations\NullAiProviderAdapter;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Policies\AIEventEvaluationPolicy;
use App\Domains\Context\Events\EventContextBuilt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(AiProviderAdapter::class, NullAiProviderAdapter::class);
    }

    public function boot(): void
    {
        Gate::policy(AIEventEvaluation::class, AIEventEvaluationPolicy::class);

        Event::listen(EventContextBuilt::class, EvaluateOnEventContextBuilt::class);
    }
}
