<?php

namespace App\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Policies\CopilotConversationPolicy;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\StepCompleted;

class CopilotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(CopilotConversation::class, CopilotConversationPolicy::class);

        // The SDK reports a stream's usage only once, at its end: each step
        // the Copilot agent completes adds its tokens to the turn, so a turn
        // that fails or is cut short can still bill what it spent.
        Event::listen(StepCompleted::class, function (StepCompleted $event): void {
            if ($event->agent instanceof CopilotAgent) {
                $event->agent->recordStep($event->response, $event->model);
            }
        });

        // Each question can fan out to several fleet queries plus one LLM
        // call: cap it per user (and per tenant) to protect cost and the DB.
        RateLimiter::for('copilot', fn (Request $request) => [
            Limit::perMinute(20)->by('copilot:user:'.$request->user()?->id),
            Limit::perMinute(120)->by('copilot:team:'.currentTeamId()),
        ]);
    }
}
