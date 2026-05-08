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
use App\Infrastructure\AI\Agents\SdkEventEvaluationAgent;
use App\Infrastructure\AI\Agents\SdkMediaAssessmentAgent;
use App\Infrastructure\AI\Listeners\AIUsageListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(EventEvaluationAgent::class, function (): EventEvaluationAgent {
            if ($this->isAiSdkConfigured()) {
                return $this->app->make(SdkEventEvaluationAgent::class);
            }

            return $this->app->make(NullEventEvaluationAgent::class);
        });

        $this->app->singletonIf(MediaAssessmentAgent::class, function (): MediaAssessmentAgent {
            if ($this->isAiSdkConfigured()) {
                return $this->app->make(SdkMediaAssessmentAgent::class);
            }

            return $this->app->make(NullMediaAssessmentAgent::class);
        });
    }

    public function boot(): void
    {
        Gate::policy(AIEventEvaluation::class, AIEvaluationPolicy::class);

        Event::listen(EventContextBuilt::class, EvaluateOnEventContextBuilt::class);
        Event::listen(EventMediaAvailable::class, EvaluateMediaOnEventMediaAvailable::class);
        Event::listen(AIEvaluationCompleted::class, BroadcastAIEvaluationCompleted::class);

        // Laravel's dispatcher does not fire parent-class listeners for
        // child events, so we register against both `AgentPrompted` and
        // `AgentStreamed` (the latter extends the former). Idempotency is
        // enforced by `RecordUsageEvent`'s unique `(team_id, event_key)`
        // constraint, so duplicate dispatches are safe.
        Event::listen(AgentPrompted::class, AIUsageListener::class);
        Event::listen(AgentStreamed::class, AIUsageListener::class);
    }

    /**
     * The Laravel AI SDK is considered "configured" when at least one provider
     * has the credentials we expect for it. Tests run without these env vars
     * and exercise the SDK via `Agent::fake(...)`, which short-circuits
     * provider resolution entirely — so the binding stays on the Null
     * implementation in the suite by default.
     */
    private function isAiSdkConfigured(): bool
    {
        $default = config('ai.default');

        if (! is_string($default) || $default === '') {
            return false;
        }

        $providerKey = match ($default) {
            'openai' => 'OPENAI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            'gemini' => 'GEMINI_API_KEY',
            'azure' => 'AZURE_OPENAI_API_KEY',
            'mistral' => 'MISTRAL_API_KEY',
            'cohere' => 'COHERE_API_KEY',
            'groq' => 'GROQ_API_KEY',
            'xai' => 'XAI_API_KEY',
            'deepseek' => 'DEEPSEEK_API_KEY',
            'openrouter' => 'OPENROUTER_API_KEY',
            default => null,
        };

        return $providerKey !== null && (string) env($providerKey) !== '';
    }
}
