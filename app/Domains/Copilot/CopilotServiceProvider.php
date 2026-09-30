<?php

namespace App\Domains\Copilot;

use App\Contracts\AI\CopilotNarrator;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Policies\CopilotConversationPolicy;
use App\Domains\Copilot\Support\TemplateCopilotNarrator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CopilotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The agent (RunCopilotAgentTurn) phrases its own answers; the
        // narrator contract only backs the deterministic, token-free path.
        $this->app->singletonIf(CopilotNarrator::class, TemplateCopilotNarrator::class);
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
