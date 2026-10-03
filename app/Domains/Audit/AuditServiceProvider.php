<?php

namespace App\Domains\Audit;

use App\Contracts\Audit\AuditLogQuery;
use App\Domains\Audit\Contracts\AuditableEventClassifier;
use App\Domains\Audit\Listeners\AuditAnyDomainEvent;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Observers\RecordTrackedChanges;
use App\Domains\Audit\Policies\AuditLogPolicy;
use App\Domains\Audit\Queries\DbAuditLogQuery;
use App\Domains\Audit\Services\ConfigAuditableEventClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditableEventClassifier::class, function ($app): AuditableEventClassifier {
            /** @var array<class-string, array{category: string, action: string, tenant_via?: string}> $events */
            $events = (array) $app['config']->get('audit.events', []);

            return new ConfigAuditableEventClassifier($events);
        });

        $this->app->singletonIf(AuditLogQuery::class, DbAuditLogQuery::class);
    }

    public function boot(): void
    {
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        // Wildcard listener — captures every dispatched event.
        // Filtering happens inside `AuditAnyDomainEvent` via the
        // `AuditableEventClassifier`, which reads `config('audit.events')`.
        if ((bool) config('audit.wildcard_listener_enabled', true)) {
            Event::listen('*', AuditAnyDomainEvent::class);
        }

        $this->observeTrackedModels();
    }

    /**
     * Historial de cambios (Spec 14 §4.4): cada modelo de
     * `audit.tracked_changes` queda observado por `RecordTrackedChanges`.
     */
    private function observeTrackedModels(): void
    {
        $tracked = config('audit.tracked_changes');

        foreach (is_array($tracked) ? array_keys($tracked) : [] as $modelClass) {
            if (is_string($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $modelClass::observe(RecordTrackedChanges::class);
            }
        }
    }
}
