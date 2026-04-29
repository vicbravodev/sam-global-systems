<?php

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Events\AIReevaluationRequested;
use App\Domains\AI\Events\FalsePositiveDetected;
use App\Domains\Assets\Events\AssetDiscovered;
use App\Domains\Assets\Events\AssetLocationUpdated;
use App\Domains\Assets\Events\AssetLocationUpdatedBroadcast;
use App\Domains\Assets\Events\AssetStatusChanged;
use App\Domains\Assets\Events\AssetStatusChangedBroadcast;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Domains\Tenancy\Events\SubscriptionCanceled;
use App\Domains\Tenancy\Events\SubscriptionUpdated;
use App\Domains\Tenancy\Events\TenantCreated;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Events\UsageUpdatedBroadcast;

/*
|--------------------------------------------------------------------------
| Audit Allowlist (Spec 14 §7)
|--------------------------------------------------------------------------
|
| The Audit domain installs a wildcard event listener that intercepts every
| dispatched event in the application. To avoid logging noise (framework
| events, queue events, broadcasting internals) only events whose FQCN is
| present in the `events` map below are persisted as `domain_event_logs` /
| `audit_logs` rows.
|
| Each map key is the fully-qualified event class name. Each value:
|   - category:    AuditCategory enum value
|   - action:      short verb-noun string used as `audit_logs.action`
|   - tenant_via:  reflection hint to resolve `team_id` from the payload.
|                  Supported strategies:
|                    - 'property:<name>'   — top-level public property
|                                            holding an int (team_id)
|                    - 'model:<property>'  — Eloquent model on `<property>`;
|                                            reads `->team_id`
|                    - 'none'              — system-level (team_id = null)
|
| New domains that ship after spec 14 lands MUST append their event FQCNs
| to this map in their post-merge rebase, NOT modify the audit domain.
| Search for `SPEC-NN-DEFERRED` markers to locate the spots.
|
*/

return [

    'queue' => env('AUDIT_QUEUE', 'audit'),

    /*
    | When true the wildcard listener is registered. Tests that want to
    | bypass the listener (e.g. legacy events at scale) can override this
    | through config().
    */
    'wildcard_listener_enabled' => env('AUDIT_WILDCARD_LISTENER_ENABLED', true),

    /*
    | Allowlist: every event the wildcard listener accepts. Keys are FQCN.
    */
    'events' => [

        // ── Tenancy ─────────────────────────────────────────────────────
        TenantCreated::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'tenancy.tenant_created',
            'tenant_via' => 'model:team',
        ],
        SubscriptionUpdated::class => [
            'category' => AuditCategory::Billing->value,
            'action' => 'tenancy.subscription_updated',
            'tenant_via' => 'model:subscription',
        ],
        SubscriptionCanceled::class => [
            'category' => AuditCategory::Billing->value,
            'action' => 'tenancy.subscription_canceled',
            'tenant_via' => 'model:subscription',
        ],
        UsageRecorded::class => [
            'category' => AuditCategory::Billing->value,
            'action' => 'tenancy.usage_recorded',
            'tenant_via' => 'property:teamId',
        ],
        UsageLimitExceeded::class => [
            'category' => AuditCategory::Billing->value,
            'action' => 'tenancy.usage_limit_exceeded',
            'tenant_via' => 'property:teamId',
        ],
        UsageUpdatedBroadcast::class => [
            'category' => AuditCategory::Billing->value,
            'action' => 'tenancy.usage_updated',
            'tenant_via' => 'property:teamId',
        ],

        // ── Assets ──────────────────────────────────────────────────────
        AssetDiscovered::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'assets.discovered',
            'tenant_via' => 'property:teamId',
        ],
        AssetLocationUpdated::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'assets.location_updated',
            'tenant_via' => 'property:teamId',
        ],
        AssetLocationUpdatedBroadcast::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'assets.location_updated_broadcast',
            'tenant_via' => 'property:teamId',
        ],
        AssetStatusChanged::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'assets.status_changed',
            'tenant_via' => 'property:teamId',
        ],
        AssetStatusChangedBroadcast::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'assets.status_changed_broadcast',
            'tenant_via' => 'property:teamId',
        ],

        // ── Normalization ───────────────────────────────────────────────
        EventNormalized::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'normalization.event_normalized',
            'tenant_via' => 'model:normalizedEvent',
        ],
        EventUnmapped::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'normalization.event_unmapped',
            'tenant_via' => 'model:rawEvent',
        ],

        // ── Context ─────────────────────────────────────────────────────
        EventContextBuilt::class => [
            'category' => AuditCategory::Domain->value,
            'action' => 'context.event_context_built',
            'tenant_via' => 'model:snapshot',
        ],

        // ── AI ──────────────────────────────────────────────────────────
        AIEvaluationCompleted::class => [
            'category' => AuditCategory::Ai->value,
            'action' => 'ai.evaluation_completed',
            'tenant_via' => 'model:evaluation',
        ],
        AIReevaluationRequested::class => [
            'category' => AuditCategory::Ai->value,
            'action' => 'ai.reevaluation_requested',
            'tenant_via' => 'none',
        ],
        FalsePositiveDetected::class => [
            'category' => AuditCategory::Ai->value,
            'action' => 'ai.false_positive_detected',
            'tenant_via' => 'model:evaluation',
        ],

        // ── Decisions  (SPEC-10-DEFERRED) ───────────────────────────────
        // SPEC-10-DEFERRED: append decisions FQCNs to allowlist when domain merged.
        // \App\Domains\Decisions\Events\DecisionOutcomeReached::class => [...]

        // ── Incidents  (SPEC-11-DEFERRED) ───────────────────────────────
        // SPEC-11-DEFERRED: append incidents FQCNs to allowlist when domain merged.
        // \App\Domains\Incidents\Events\IncidentCreated::class => [...]
        // \App\Domains\Incidents\Events\IncidentEscalated::class => [...]
        // \App\Domains\Incidents\Events\IncidentResolved::class => [...]

        // ── Automation (SPEC-12-DEFERRED) ───────────────────────────────
        // SPEC-12-DEFERRED: append automation FQCNs to allowlist when domain merged.
        // \App\Domains\Automation\Events\ActionExecuted::class => [...]
        // \App\Domains\Automation\Events\ActionFailed::class => [...]
        // \App\Domains\Automation\Events\WorkflowCompleted::class => [...]

        // ── Notifications (SPEC-13-DEFERRED) ────────────────────────────
        // SPEC-13-DEFERRED: append notifications FQCNs to allowlist when domain merged.
        // \App\Domains\Notifications\Events\NotificationCreated::class => [...]
        // \App\Domains\Notifications\Events\NotificationDelivered::class => [...]
        // \App\Domains\Notifications\Events\NotificationFailed::class => [...]

        // ── Tenant Config (SPEC-16-DEFERRED) ────────────────────────────
        // SPEC-16-DEFERRED: append tenant-config FQCNs to allowlist when domain merged.
        // \App\Domains\TenantConfig\Events\TenantSettingUpdated::class => [...]
        // \App\Domains\TenantConfig\Events\TenantAIProfileChanged::class => [...]
    ],

];
