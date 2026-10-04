<?php

namespace App\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\AI\Commands\ClefBackfillCommand;
use App\Domains\AI\Commands\LabelEventsCommand;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Listeners\AssessPendingMediaOnEvaluationCompleted;
use App\Domains\AI\Listeners\BroadcastAIEvaluationCompleted;
use App\Domains\AI\Listeners\DispatchClefShadowEvaluation;
use App\Domains\AI\Listeners\EvaluateMediaOnEventMediaAvailable;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Listeners\RecordOperatorVerdictOnIncidentResolved;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Policies\AIEvaluationPolicy;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Incidents\Events\IncidentResolved;
use App\Infrastructure\AI\Agents\SdkEventEvaluationAgent;
use App\Infrastructure\AI\Agents\SdkMediaAssessmentAgent;
use App\Infrastructure\AI\Listeners\AgentCallLogListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;

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

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClefBackfillCommand::class,
                LabelEventsCommand::class,
            ]);
        }

        Event::listen(EventContextBuilt::class, EvaluateOnEventContextBuilt::class);
        Event::listen(EventMediaAvailable::class, EvaluateMediaOnEventMediaAvailable::class);
        Event::listen(AIEvaluationCompleted::class, BroadcastAIEvaluationCompleted::class);
        // Backfill assessments for media that persisted before this evaluation
        // existed (extraction and text evaluation race on separate queues).
        Event::listen(AIEvaluationCompleted::class, AssessPendingMediaOnEvaluationCompleted::class);
        // Medición temporal Clef vs GPT; se apaga sola (ai.clef.shadow_until).
        Event::listen(AIEvaluationCompleted::class, DispatchClefShadowEvaluation::class);
        // "Descartar como falso positivo" = etiqueta humana para la IA.
        Event::listen(IncidentResolved::class, RecordOperatorVerdictOnIncidentResolved::class);

        // Sin listener de cobro sobre los eventos del SDK: los tokens los cobra
        // quien llama (EvaluateEventWithAI / EvaluateEventMultimodally /
        // RecordCopilotUsage) con su propia event_key; otro cobro aquí
        // duplicaría la misma llamada.

        // `ai.agent.called`: una línea por invocación (sin prompt ni respuesta).
        Event::listen(PromptingAgent::class, [AgentCallLogListener::class, 'started']);
        Event::listen(StreamingAgent::class, [AgentCallLogListener::class, 'started']);
        Event::listen(AgentPrompted::class, [AgentCallLogListener::class, 'completed']);
        Event::listen(AgentStreamed::class, [AgentCallLogListener::class, 'completed']);
        Event::listen(AgentFailed::class, [AgentCallLogListener::class, 'failed']);
    }

    /**
     * The Laravel AI SDK is considered "configured" when the default provider
     * declares a non-empty API key in `config('ai.providers.*')`. Reading the
     * key from config (never `env()` directly) keeps this `config:cache`-safe.
     * Tests run without provider keys and exercise the SDK via
     * `Agent::fake(...)`, which short-circuits provider resolution entirely —
     * so the binding stays on the Null implementation in the suite by default.
     * Providers authenticating without a `key` (e.g. bedrock via IAM
     * credentials) are treated as not configured.
     */
    private function isAiSdkConfigured(): bool
    {
        $default = config('ai.default');

        if (! is_string($default) || $default === '') {
            return false;
        }

        $provider = config("ai.providers.{$default}");

        return is_array($provider) && trim((string) ($provider['key'] ?? '')) !== '';
    }
}
