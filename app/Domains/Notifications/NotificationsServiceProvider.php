<?php

namespace App\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry as ChannelDriverRegistryContract;
use App\Contracts\NullImplementations\NullTenantNotificationPoliciesResolver;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Channels\ChannelDriverRegistry;
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
    /**
     * String-based listeners for cross-domain events that ship in parallel PRs.
     * Listening by FQCN string lets this domain compile before specs 10/11/12 land.
     *
     * @var array<string, array<int, class-string>>
     */
    private const CROSS_DOMAIN_LISTENERS = [
        // SPEC-11-DEFERRED
        'App\\Domains\\Incidents\\Events\\IncidentCreated' => [
            Listeners\NotifyOnIncidentCreated::class,
        ],
        'App\\Domains\\Incidents\\Events\\IncidentStatusChanged' => [
            Listeners\NotifyOnIncidentStatusChanged::class,
        ],
        'App\\Domains\\Incidents\\Events\\IncidentClosed' => [
            Listeners\NotifyOnIncidentStatusChanged::class,
        ],
        // SPEC-12-DEFERRED
        'App\\Domains\\Automation\\Events\\ActionExecutionCompleted' => [
            Listeners\NotifyOnActionExecutionCompleted::class,
        ],
    ];

    public function register(): void
    {
        $this->app->singletonIf(
            ChannelDriverRegistryContract::class,
            ChannelDriverRegistry::class,
        );

        // SPEC-16-DEFERRED: bound to a Null resolver until TenantConfig domain ships
        // its `notification_policies` table.
        $this->app->singletonIf(
            TenantNotificationPoliciesResolver::class,
            NullTenantNotificationPoliciesResolver::class,
        );
    }

    public function boot(): void
    {
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(NotificationTemplate::class, NotificationTemplatePolicy::class);
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
        Gate::policy(NotificationPreference::class, NotificationPreferencePolicy::class);

        foreach (self::CROSS_DOMAIN_LISTENERS as $eventClass => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($eventClass, $listener);
            }
        }
    }
}
