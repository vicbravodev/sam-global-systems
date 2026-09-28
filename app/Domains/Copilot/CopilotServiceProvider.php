<?php

namespace App\Domains\Copilot;

use App\Contracts\AI\CopilotNarrator;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Policies\CopilotConversationPolicy;
use App\Domains\Copilot\Support\TemplateCopilotNarrator;
use App\Infrastructure\AI\Agents\SdkCopilotNarrator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CopilotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Same rule as the event evaluator: the LLM narrator is only used when
        // the default AI provider has a key; otherwise answers are narrated
        // from the grounded tool highlights (no tokens consumed).
        $this->app->singletonIf(CopilotNarrator::class, function (): CopilotNarrator {
            $provider = (string) config('ai.default');
            $key = config("ai.providers.{$provider}.key");

            if (is_string($key) && $key !== '') {
                return $this->app->make(SdkCopilotNarrator::class);
            }

            return $this->app->make(TemplateCopilotNarrator::class);
        });
    }

    public function boot(): void
    {
        Gate::policy(CopilotConversation::class, CopilotConversationPolicy::class);

        // Each question can fan out to several fleet queries plus one LLM
        // call: cap it per user (and per tenant) to protect cost and the DB.
        RateLimiter::for('copilot', fn (Request $request) => [
            Limit::perMinute(20)->by('copilot:user:'.$request->user()?->id),
            Limit::perMinute(120)->by('copilot:team:'.currentTeamId()),
        ]);
    }
}
