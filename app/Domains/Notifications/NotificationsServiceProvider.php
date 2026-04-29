<?php

namespace App\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry as ChannelDriverRegistryContract;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Incidents\Events\IncidentClosed;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Notifications\Channels\ChannelDriverRegistry;
use App\Domains\Notifications\Listeners\NotifyOnActionExecuted;
use App\Domains\Notifications\Listeners\NotifyOnIncidentCreated;
use App\Domains\Notifications\Listeners\NotifyOnIncidentStatusChanged;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Policies\NotificationChannelPolicy;
use App\Domains\Notifications\Policies\NotificationPolicy;
use App\Domains\Notifications\Policies\NotificationPreferencePolicy;
use App\Domains\Notifications\Policies\NotificationTemplatePolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(
            ChannelDriverRegistryContract::class,
            ChannelDriverRegistry::class,
        );
    }

    public function boot(): void
    {
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(NotificationTemplate::class, NotificationTemplatePolicy::class);
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
        Gate::policy(NotificationPreference::class, NotificationPreferencePolicy::class);

        Event::listen(IncidentCreated::class, NotifyOnIncidentCreated::class);
        Event::listen(IncidentStatusChanged::class, NotifyOnIncidentStatusChanged::class);
        Event::listen(IncidentClosed::class, NotifyOnIncidentStatusChanged::class);
        Event::listen(ActionExecuted::class, NotifyOnActionExecuted::class);
    }
}
