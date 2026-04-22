# Audit

## 1. Purpose

Record and preserve all significant actions, events, decisions, and changes within SAM for compliance, debugging, forensic analysis, and explainability. This domain provides an immutable, append-only audit trail that links every system action — human or automated — to its originating context. It enables correlation-based tracing across the entire event pipeline, from raw ingestion through AI evaluation to incident resolution.

## 2. Responsibilities

- Record audit logs for every significant user, system, AI, and automation action
- Persist domain events with correlation and causation IDs for pipeline tracing
- Track system-level traces and spans for observability and performance debugging
- Capture before/after change history for critical entities
- Link traces across modules to build end-to-end incident timelines
- Enforce append-only semantics — no edits, no deletions
- Maintain strict tenant isolation across all audit data

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| All modules | Domain events (dispatched throughout the application) | Event listeners |
| All modules | Explicit audit log calls for sensitive actions | Service call to `RecordAuditEvent` |
| HTTP middleware | Request metadata (IP, user agent) for user action auditing | Middleware |
| Model observers | Before/after state for entity changes | Eloquent events |
| AI module | AI evaluation decisions and confidence scores | Domain events |
| Automation module | Action execution outcomes | Domain events |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Frontend | Audit log timeline for incidents and entities | Inertia pages / API |
| Analytics module | Aggregated event counts, trace durations | Queries / materialized views |
| Compliance / Export | Full audit trail for regulatory review | API / file export |

## 4. Entities

### 4.1 Audit Logs (`audit_logs`)

Core audit trail recording who did what, when, and why.

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('actor_type'); // enum
    $table->unsignedBigInteger('actor_id')->nullable();
    $table->string('action');
    $table->string('entity_type');
    $table->unsignedBigInteger('entity_id')->nullable();
    $table->string('source_type')->nullable();
    $table->string('source_reference_id')->nullable();
    $table->text('summary');
    $table->jsonb('metadata_json')->nullable();
    $table->string('ip_address')->nullable();
    $table->string('user_agent')->nullable();
    $table->timestamp('occurred_at');
    $table->timestamps();

    $table->index(['team_id', 'occurred_at']);
    $table->index(['entity_type', 'entity_id']);
    $table->index(['actor_type', 'actor_id']);
});
```

**Enum `AuditActorType`**: `User`, `System`, `Ai`, `Job`, `WebhookSource`, `Automation`

### 4.2 Domain Event Logs (`domain_event_logs`)

Durable log of every domain event dispatched in the system, with correlation tracking.

```php
Schema::create('domain_event_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('event_name');
    $table->string('aggregate_type');
    $table->unsignedBigInteger('aggregate_id')->nullable();
    $table->jsonb('payload_json')->nullable();
    $table->timestamp('occurred_at');
    $table->uuid('correlation_id')->nullable();
    $table->uuid('causation_id')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'occurred_at']);
    $table->index('correlation_id');
    $table->index(['aggregate_type', 'aggregate_id']);
});
```

### 4.3 System Traces (`system_traces`)

Distributed-tracing-style spans for tracking operations across modules.

```php
Schema::create('system_traces', function (Blueprint $table) {
    $table->id();
    $table->uuid('trace_id');
    $table->uuid('span_id');
    $table->uuid('parent_span_id')->nullable();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('module_name');
    $table->string('operation_name');
    $table->string('status'); // enum
    $table->timestamp('started_at');
    $table->timestamp('finished_at')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->jsonb('input_reference_json')->nullable();
    $table->jsonb('output_reference_json')->nullable();
    $table->text('error_message')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();

    $table->index('trace_id');
    $table->index(['team_id', 'module_name']);
});
```

**Enum `TraceStatus`**: `Started`, `Completed`, `Failed`

### 4.4 Change Histories (`change_histories`)

Before/after snapshots for critical entity mutations.

```php
Schema::create('change_histories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('entity_type');
    $table->unsignedBigInteger('entity_id');
    $table->string('changed_by_type'); // enum
    $table->unsignedBigInteger('changed_by_id')->nullable();
    $table->string('change_type'); // enum
    $table->jsonb('before_json')->nullable();
    $table->jsonb('after_json')->nullable();
    $table->jsonb('changed_fields_json')->nullable();
    $table->text('reason')->nullable();
    $table->timestamp('occurred_at');
    $table->timestamps();

    $table->index(['entity_type', 'entity_id', 'occurred_at']);
});
```

**Enum `ChangeActorType`**: `User`, `System`, `Ai`, `Automation`

**Enum `ChangeType`**: `Created`, `Updated`, `Deleted`, `StatusChanged`, `Reassigned`, `Reclassified`, `ConfigChanged`, `OverrideApplied`

### 4.5 Trace Links (`trace_links`)

Cross-entity relationship links for building end-to-end audit chains.

```php
Schema::create('trace_links', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->uuid('trace_id');
    $table->string('source_type');
    $table->unsignedBigInteger('source_id');
    $table->string('target_type');
    $table->unsignedBigInteger('target_id');
    $table->string('relation_type'); // enum
    $table->timestamps();
});
```

**Enum `TraceRelationType`**: `CausedBy`, `Generated`, `Triggered`, `LinkedTo`, `EscalatedFrom`, `ReevaluatedFrom`

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `RecordAuditEvent` | Create an `audit_logs` entry with actor, action, entity, and metadata. Automatically captures IP address and user agent from the current request context when the actor is a user. |
| `StoreDomainEvent` | Persist a domain event to `domain_event_logs` with its correlation and causation IDs. Called by a global event listener that intercepts all domain events. |
| `RecordEntityChange` | Create a `change_histories` entry capturing the before/after state of a model. Used by model observers on critical entities (Incident, Asset, Decision, Subscription). |
| `StartSystemTrace` | Create a root `system_traces` span with a new `trace_id` and `span_id`. Returns the trace context for propagation to child spans. |
| `AppendTraceSpan` | Add a child span to an existing trace, linking to the parent via `parent_span_id`. Used by downstream modules to continue the trace chain. |
| `BuildTraceForIncident` | Given an incident ID, walk `trace_links` and `domain_event_logs` to reconstruct the full timeline from raw event ingestion through AI evaluation, decision, and action execution. Returns a structured timeline. |

## 6. Jobs

This domain does not define queue jobs. Audit recording is performed synchronously or via lightweight event listeners to ensure audit data is never lost due to queue failures. High-volume domain event logging uses a buffered write pattern — events are collected during a request and flushed in a single batch insert on request termination.

## 7. Domain Events

The Audit module does not dispatch its own domain events — it *consumes* events from all other modules. Dispatching events from the audit module would create infinite recursion.

**Events consumed (non-exhaustive):**

| Source Module | Events |
|---------------|--------|
| Tenancy | `TenantCreated`, `SubscriptionUpdated`, `UsageRecorded` |
| Access | `RoleAssigned`, `PermissionGranted` |
| Integrations | `IntegrationConnected`, `IntegrationSyncCompleted` |
| Ingestion | `RawEventReceived` |
| AI | `AIEvaluationCompleted` |
| Decisions | `DecisionOutcomeReached` |
| Incidents | `IncidentCreated`, `IncidentEscalated`, `IncidentResolved` |
| Automation | `ActionExecuted`, `ActionFailed`, `WorkflowCompleted` |
| Notifications | `NotificationCreated`, `NotificationDelivered`, `NotificationFailed` |
| Tenant Config | `TenantSettingUpdated`, `TenantAIProfileChanged` |

## 8. Broadcasting Events

None. Audit data is read on-demand via API endpoints — it is not pushed to the frontend in real time.

## 9. APIs / Endpoints

All tenant-scoped endpoints are prefixed with `/{current_team}` and protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/{current_team}/audit/logs` | `AuditLogController@index` | List audit logs (filterable by actor, entity, date range) |
| `GET` | `/{current_team}/audit/logs/{log}` | `AuditLogController@show` | View audit log detail with metadata |
| `GET` | `/{current_team}/audit/events` | `DomainEventLogController@index` | List domain event logs (filterable by event name, correlation ID) |
| `GET` | `/{current_team}/audit/changes` | `ChangeHistoryController@index` | List change history (filterable by entity type/ID) |
| `GET` | `/{current_team}/audit/traces/{traceId}` | `SystemTraceController@show` | View a complete trace with all spans |
| `GET` | `/{current_team}/audit/incidents/{incident}/timeline` | `IncidentTimelineController@show` | Build and display the full audit timeline for an incident |

## 10. Business Rules

1. **Append-only** — Audit logs, domain event logs, change histories, and system traces are never edited or deleted. There are no `UPDATE` or `DELETE` operations on these tables. Soft deletes are not used.
2. **Correlation propagation** — Every pipeline flow (raw event → normalization → context → AI → decision → incident → action) shares a single `correlation_id`. This ID is generated at ingestion and propagated through every domain event and system trace span.
3. **Mandatory audit for sensitive actions** — Every action that modifies security-sensitive state (role changes, permission grants, credential updates, subscription changes, AI override decisions) MUST produce an `audit_logs` entry. This is enforced by action classes calling `RecordAuditEvent` directly.
4. **Before/after snapshots** — Change histories capture the full `before_json` and `after_json` state for critical entities. The `changed_fields_json` column contains only the names of fields that changed, for efficient querying.
5. **Tenant isolation** — Audit data is strictly tenant-scoped. A tenant can only query their own audit logs, traces, and change histories. Enforced by `BelongsToTenant` trait on all models except system-level records (`team_id = null`).
6. **No circular event logging** — The Audit module listens to domain events but does NOT dispatch events of its own, preventing infinite recursion.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **All modules** | Every module dispatches domain events that the Audit module captures via `StoreDomainEvent`. |
| **Ingestion** | Generates the initial `correlation_id` for pipeline tracing. The Audit module picks up `RawEventReceived` as the trace origin. |
| **AI** | AI evaluation results are logged as audit events with confidence scores and model metadata. |
| **Decisions** | Decision outcomes with their reasoning chain are stored in domain event logs for explainability. |
| **Incidents** | Incident lifecycle (creation, escalation, resolution) produces change histories. `BuildTraceForIncident` reconstructs the full timeline. |
| **Automation** | Action executions and workflow completions are captured for compliance auditing. |
| **Access** | Role and permission changes are audit-logged for security compliance. |
| **Tenant Config** | Configuration changes (settings, AI profiles, rule overrides) produce change history records. |
| **Tenancy** | `team_id` FK on all tenant-scoped entities. Uses `BelongsToTenant` trait. |

## 12. Usage Metering

None. Audit recording is a platform cost, not a tenant-metered activity.

## 13. Technical Considerations

### Performance

- `audit_logs` and `domain_event_logs` will grow rapidly. Partition by `occurred_at` month when tables exceed 100M rows.
- Batch inserts: During a single request, buffer domain event logs and flush them in a single `insert` call on request termination (via `terminate` middleware or `app()->terminating()` callback).
- Read queries on audit tables should always include time-range filters to leverage the `(team_id, occurred_at)` index.
- For incident timelines, pre-compute `trace_links` at event dispatch time rather than walking relationships at query time.

### Storage

- JSON columns (`payload_json`, `before_json`, `after_json`) can be large. Consider compressing payloads for events with bodies > 10KB.
- Audit data is never purged automatically. Implement a configurable retention policy per tenant (e.g., 1 year, 3 years, indefinite) with archival to RustFS for data beyond the retention window.

### Correlation ID Propagation

```php
// Shared context bag — set at ingestion, read by all downstream modules
app()->singleton('pipeline.context', fn () => new PipelineContext(
    correlationId: Str::uuid()->toString(),
));
```

Each domain event listener reads `app('pipeline.context')->correlationId` and passes it to `StoreDomainEvent`. Job dispatches propagate the context via job middleware or payload metadata.

### Buffered Event Logging

```php
// In a request termination callback
app()->terminating(function () {
    $buffer = app(DomainEventBuffer::class);
    if ($buffer->isNotEmpty()) {
        DomainEventLog::insert($buffer->flush());
    }
});
```

### Model Observer Pattern

Critical models register observers that call `RecordEntityChange`:

```php
// app/Domains/Audit/Observers/AuditableObserver.php
class AuditableObserver
{
    public function updating(Model $model): void
    {
        if ($model->isDirty()) {
            app(RecordEntityChange::class)->execute(
                entityType: $model->getMorphClass(),
                entityId: $model->getKey(),
                changeType: ChangeType::Updated,
                before: $model->getOriginal(),
                after: $model->getAttributes(),
                changedFields: array_keys($model->getDirty()),
            );
        }
    }
}
```

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_audit_log_created_for_user_action` | A user-initiated action (e.g., incident creation) produces an `audit_logs` entry with correct `actor_type`, `action`, and `entity_type` |
| `test_domain_event_log_records_with_correlation_id` | A domain event dispatched during a pipeline flow is stored in `domain_event_logs` with the correct `correlation_id` |
| `test_change_history_captures_before_after` | Updating an incident's status produces a `change_histories` record with accurate `before_json`, `after_json`, and `changed_fields_json` |
| `test_system_trace_links_pipeline_steps` | A trace started at ingestion and continued through normalization and AI produces linked spans sharing the same `trace_id` |
| `test_audit_data_is_tenant_isolated` | Querying audit logs with a tenant scope returns only that tenant's records, even when records for other tenants exist |
| `test_audit_logs_are_append_only` | Attempting to update or delete an `audit_logs` record raises an exception or is silently prevented |
| `test_correlation_id_propagates_through_pipeline` | A single `correlation_id` generated at ingestion appears in domain event logs from ingestion, normalization, AI, and decisions |
| `test_change_history_records_all_change_types` | Each `ChangeType` enum value (created, updated, deleted, status_changed, etc.) can be stored and queried correctly |
| `test_build_trace_for_incident_returns_full_timeline` | `BuildTraceForIncident` returns a chronological list of all events, spans, and changes linked to the incident |
| `test_buffered_events_flushed_on_request_termination` | Domain events buffered during a request are batch-inserted into `domain_event_logs` at termination |
| `test_ip_and_user_agent_captured_for_user_actions` | An audit log created for a user action includes `ip_address` and `user_agent` from the request |
| `test_trace_links_connect_source_and_target_entities` | Creating a `trace_links` record correctly links a decision to its originating raw event |
