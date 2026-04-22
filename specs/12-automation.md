# Automation

## 1. Purpose

Execute automated actions (notifications, escalations, ticket creation, status changes) triggered by decisions, incidents, or policies. This domain separates decision from execution — the Decisions module determines *what* should happen, the Automation module carries it out. Supports both standalone actions and multi-step workflows with sequential execution, delays, and conditional branching.

## 2. Responsibilities

- Define reusable action templates (email, SMS, WhatsApp, push, ticket creation, webhooks, etc.)
- Author multi-step automation workflows triggered by domain events
- Execute actions idempotently with full audit trail
- Manage escalation chains with ordered steps, delays, and fallbacks
- Retry failed actions according to configurable retry policies
- Support human confirmation gates for sensitive actions
- Track workflow execution state from start to completion
- Broadcast action execution results to connected clients in real time

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Decisions module | Decision outcomes triggering actions | Domain event (`DecisionOutcomeReached`) |
| Incidents module | Incident created/escalated events | Domain event (`IncidentCreated`, `IncidentEscalated`) |
| Tenant Config | Escalation policies, automation level | Service call to `ResolveTenantAIProfile` |
| Admin / API | Manual action trigger, workflow management | Inertia pages / API |
| Scheduler | Deferred action execution | `ExecuteActionJob` with delay |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Notifications module | Notification dispatch requests | Service call to `SendNotification` |
| Incidents module | Ticket creation, status changes, reassignments | Service call to incident actions |
| Audit module | Action execution logs, workflow traces | Domain events |
| Frontend (Soketi) | Action execution results | `ActionExecutedBroadcast` on `private-accounts.{teamId}` |
| External systems | Webhook calls for integrations | HTTP POST via `call_webhook` action type |

## 4. Entities

### 4.1 Action Templates (`action_templates`)

Reusable definitions for automated actions. System-wide templates have `team_id = null`; tenant-specific templates override or extend the catalog.

```php
Schema::create('action_templates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->string('action_type'); // enum
    $table->string('channel')->nullable();
    $table->string('subject_template')->nullable();
    $table->text('body_template')->nullable();
    $table->jsonb('parameters_schema_json')->nullable();
    $table->jsonb('config_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Enum `ActionType`**: `SendEmail`, `SendWhatsapp`, `SendSms`, `SendPush`, `CreateTicket`, `AssignIncident`, `Escalate`, `UpdateAssetState`, `RequestHumanReview`, `CallWebhook`

### 4.2 Automation Workflows (`automation_workflows`)

Multi-step workflow definitions. System-wide workflows have `team_id = null`.

```php
Schema::create('automation_workflows', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('trigger_type'); // enum
    $table->jsonb('trigger_conditions_json')->nullable();
    $table->string('status'); // enum
    $table->unsignedInteger('version')->default(1);
    $table->jsonb('steps_json');
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['team_id', 'trigger_type', 'is_active']);
});
```

**Enum `WorkflowTriggerType`**: `DecisionOutcome`, `IncidentCreated`, `IncidentEscalated`, `PriorityChanged`, `MediaArrived`, `ManualTrigger`

**Enum `WorkflowStatus`**: `Active`, `Inactive`, `Draft`

### 4.3 Action Executions (`action_executions`)

Individual action execution records with full provenance tracking.

```php
Schema::create('action_executions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('action_type');
    $table->string('source_type'); // enum
    $table->string('source_reference_id')->nullable();
    $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('decision_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('automation_workflow_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('action_template_id')->nullable()->constrained()->nullOnDelete();
    $table->string('status'); // enum
    $table->string('execution_mode'); // enum
    $table->string('target_type')->nullable();
    $table->string('target_reference')->nullable();
    $table->jsonb('payload_json')->nullable();
    $table->jsonb('response_json')->nullable();
    $table->text('error_message')->nullable();
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->timestamp('executed_at')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'status']);
    $table->index('incident_id');
});
```

**Enum `ActionExecutionSourceType`**: `Decision`, `Incident`, `Escalation`, `Workflow`, `Manual`

**Enum `ActionExecutionStatus`**: `Pending`, `Queued`, `Running`, `Completed`, `Failed`, `Cancelled`, `Retrying`

**Enum `ExecutionMode`**: `Sync`, `Async`, `Deferred`, `RequiresConfirmation`

### 4.4 Escalation Steps (`escalation_steps`)

Ordered steps within an automation workflow for escalation chains.

```php
Schema::create('escalation_steps', function (Blueprint $table) {
    $table->id();
    $table->foreignId('automation_workflow_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('step_order');
    $table->string('step_type'); // enum
    $table->string('target_type')->nullable();
    $table->string('target_reference')->nullable();
    $table->unsignedInteger('delay_seconds')->nullable();
    $table->jsonb('conditions_json')->nullable();
    $table->string('fallback_action')->nullable();
    $table->timestamps();
});
```

**Enum `EscalationStepType`**: `Notify`, `Assign`, `Escalate`, `WaitForAck`, `CreateTicket`, `RequestConfirmation`, `CallExternalSystem`

### 4.5 Action Execution Logs (`action_execution_logs`)

Detailed log entries for each action execution attempt.

```php
Schema::create('action_execution_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('action_execution_id')->constrained()->cascadeOnDelete();
    $table->string('log_type'); // enum
    $table->text('message');
    $table->jsonb('payload_json')->nullable();
    $table->timestamps();
});
```

**Enum `ActionLogType`**: `Info`, `Warning`, `Error`, `Retry`, `ExternalResponse`

### 4.6 Workflow Executions (`workflow_executions`)

Tracks runtime state of each workflow run.

```php
Schema::create('workflow_executions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('automation_workflow_id')->constrained()->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('source_type');
    $table->string('source_reference_id')->nullable();
    $table->string('status'); // enum
    $table->timestamp('started_at');
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

**Enum `WorkflowExecutionStatus`**: `Running`, `Completed`, `Failed`, `Cancelled`

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `ExecuteAction` | Resolve the action template, build the payload, dispatch the appropriate handler (notification, ticket, webhook, etc.), and record the execution result. |
| `RunAutomationWorkflow` | Instantiate a `workflow_executions` record, iterate through `steps_json` sequentially, respecting delays and conditions, dispatching an action execution per step. |
| `TriggerEscalationWorkflow` | Find matching escalation workflows for a given trigger event, evaluate `trigger_conditions_json`, and dispatch `RunAutomationWorkflowJob` for each match. |
| `ResolveActionTemplate` | Look up the action template by code, preferring tenant-specific templates over system-wide ones. |
| `RetryFailedAction` | Re-queue a failed `action_execution` with incremented `attempts`, respecting max retry limits and backoff policy. |

## 6. Jobs

### `ExecuteActionJob`

- **Queue**: `automation`
- **Retry**: 3 attempts
- **Backoff**: `[10, 60, 300]` (exponential)
- **Logic**:
  1. Load the `action_execution` record
  2. Set status to `running`, increment `attempts`
  3. Call `ExecuteAction` service
  4. On success: set status to `completed`, record `executed_at`, log result
  5. On failure: set status to `failed` or `retrying`, log error, re-queue if retries remain
  6. Dispatch `ActionExecuted` or `ActionFailed` domain event

### `RunAutomationWorkflowJob`

- **Queue**: `automation`
- **Retry**: 2 attempts
- **Logic**:
  1. Create a `workflow_executions` record with status `running`
  2. Iterate through `steps_json` in order
  3. For each step: create an `action_execution`, dispatch `ExecuteActionJob`
  4. If a step has `delay_seconds`, schedule the next step with a delay
  5. On all steps complete: set workflow execution status to `completed`
  6. On failure: set status to `failed`, execute fallback if configured
  7. Dispatch `WorkflowCompleted` domain event

### `RetryActionExecutionJob`

- **Queue**: `automation`
- **Logic**:
  1. Load failed `action_execution` records eligible for retry
  2. Call `RetryFailedAction` service for each
  3. Dispatch `ExecuteActionJob` with appropriate backoff delay

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `ActionExecuted` | Action execution completes successfully | `teamId`, `actionExecutionId`, `actionType`, `sourceType`, `incidentId` |
| `ActionFailed` | Action execution fails after all retries | `teamId`, `actionExecutionId`, `actionType`, `errorMessage` |
| `WorkflowCompleted` | All steps in a workflow finish | `teamId`, `workflowExecutionId`, `automationWorkflowId`, `status` |
| `EscalationStepExecuted` | An individual escalation step completes | `teamId`, `escalationStepId`, `stepType`, `workflowExecutionId` |

## 8. Broadcasting Events

### `ActionExecutedBroadcast`

Broadcast on `private-accounts.{teamId}` when an action execution completes (success or failure).

```php
namespace App\Domains\Automation\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ActionExecutedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $actionExecutionId,
        public readonly string $actionType,
        public readonly string $status,
        public readonly ?int $incidentId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'action.executed';
    }

    public function broadcastWith(): array
    {
        return [
            'action_execution_id' => $this->actionExecutionId,
            'action_type' => $this->actionType,
            'status' => $this->status,
            'incident_id' => $this->incidentId,
        ];
    }
}
```

## 9. APIs / Endpoints

All tenant-scoped endpoints are prefixed with `/{current_team}` and protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/{current_team}/automation/workflows` | `AutomationWorkflowController@index` | List automation workflows |
| `POST` | `/{current_team}/automation/workflows` | `AutomationWorkflowController@store` | Create a new workflow |
| `GET` | `/{current_team}/automation/workflows/{workflow}` | `AutomationWorkflowController@show` | View workflow detail |
| `PUT` | `/{current_team}/automation/workflows/{workflow}` | `AutomationWorkflowController@update` | Update a workflow |
| `DELETE` | `/{current_team}/automation/workflows/{workflow}` | `AutomationWorkflowController@destroy` | Delete a workflow |
| `POST` | `/{current_team}/automation/workflows/{workflow}/trigger` | `AutomationWorkflowController@trigger` | Manually trigger a workflow |
| `GET` | `/{current_team}/automation/executions` | `ActionExecutionController@index` | List action executions (filterable by status, incident) |
| `GET` | `/{current_team}/automation/executions/{execution}` | `ActionExecutionController@show` | View execution detail with logs |
| `POST` | `/{current_team}/automation/executions/{execution}/retry` | `ActionExecutionController@retry` | Retry a failed execution |
| `POST` | `/{current_team}/automation/executions/{execution}/confirm` | `ActionExecutionController@confirm` | Confirm a pending confirmation action |
| `POST` | `/{current_team}/automation/executions/{execution}/cancel` | `ActionExecutionController@cancel` | Cancel a pending/queued execution |
| `GET` | `/{current_team}/automation/templates` | `ActionTemplateController@index` | List action templates |
| `POST` | `/{current_team}/automation/templates` | `ActionTemplateController@store` | Create a custom action template |

## 10. Business Rules

1. **Idempotent execution** — The same action for the same source event must not execute twice. Enforced by checking `(source_type, source_reference_id, action_type)` uniqueness before creating an `action_execution`.
2. **Full audit trail** — Every action execution produces log entries in `action_execution_logs`. No action runs without a trace.
3. **Human confirmation gate** — Actions with `execution_mode = requires_confirmation` pause in `pending` status until a user explicitly confirms via the API. Unconfirmed actions expire after a configurable timeout.
4. **Retry policy** — Failed actions retry up to the configured maximum (default 3) with exponential backoff `[10, 60, 300]` seconds. After exhausting retries, the action is marked `failed` and `ActionFailed` event is dispatched.
5. **Sequential workflow execution** — Workflow steps execute in order. A step with `delay_seconds` pauses the chain. If a step fails and no fallback is configured, the workflow fails.
6. **Tenant template precedence** — When resolving an action template by code, tenant-specific templates (`team_id = current team`) take precedence over system-wide templates (`team_id = null`).
7. **Escalation fallback** — If an escalation step fails and `fallback_action` is set, the fallback action executes before the workflow is marked as failed.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Decisions** | Listens to `DecisionOutcomeReached` events to trigger action executions and workflows. `decision_id` FK links executions to their originating decision. |
| **Incidents** | Listens to `IncidentCreated` and `IncidentEscalated` events. Can create tickets, reassign incidents, and change incident status. `incident_id` FK on executions. |
| **Notifications** | Delegates notification delivery (email, SMS, push, WhatsApp) to the Notifications module via `SendNotification` service. |
| **Tenant Config** | Reads escalation policies and automation level from `ResolveTenantAIProfile` and `ResolveTenantNotificationPolicy`. |
| **Audit** | All domain events (`ActionExecuted`, `ActionFailed`, `WorkflowCompleted`) are captured by the Audit module for compliance. |
| **Tenancy** | `team_id` FK on all tenant-scoped entities. Uses `BelongsToTenant` trait. Emits `incident_workflows` usage events. |
| **Access** | Policies check team membership and permissions before allowing workflow CRUD and manual triggers. |

## 12. Usage Metering

| Meter Code | When Recorded |
|------------|---------------|
| `incident_workflows` | 1 event per `workflow_executions` record created (via `RecordUsageEvent`) |

```php
app(RecordUsageEvent::class)->execute(
    teamId: $workflowExecution->team_id,
    meterCode: 'incident_workflows',
    quantity: 1,
    eventKey: "workflow_exec_{$workflowExecution->id}",
);
```

## 13. Technical Considerations

### Idempotency

- Before creating an `action_execution`, check for an existing record with the same `(source_type, source_reference_id, action_type, target_reference)`. Use `firstOrCreate` pattern.
- Workflow executions are idempotent by `(automation_workflow_id, source_type, source_reference_id)`.

### Performance

- Action templates are cached in Valkey by `(team_id, code)` with 10-minute TTL, invalidated on update.
- Workflow matching (`trigger_type + trigger_conditions_json`) is evaluated in-memory after loading active workflows for the tenant (typically < 50 per tenant).

### Delay Handling

- Steps with `delay_seconds` use Laravel's `delay()` method on `ExecuteActionJob` dispatch. The job is scheduled for future execution via the queue.
- For long delays (> 1 hour), consider using `scheduled_at` timestamp and a periodic sweep job instead of queue delay.

### Confirmation Expiry

- Actions in `requires_confirmation` mode have a configurable TTL (default 30 minutes). A scheduled job sweeps expired confirmations and marks them `cancelled`.

### Webhook Actions

- `call_webhook` action type sends an HTTP POST to the configured URL with the payload. Timeout is 30 seconds. Response is stored in `response_json`.
- Webhook URLs must be validated against an allowlist to prevent SSRF.

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_action_executes_from_decision` | A `DecisionOutcomeReached` event triggers the correct action template execution and creates an `action_execution` with status `completed` |
| `test_workflow_runs_sequential_steps` | A multi-step workflow executes steps in order, each creating its own `action_execution` |
| `test_failed_action_retries` | A failed action is retried up to 3 times with exponential backoff, status transitions through `retrying` back to `running` |
| `test_idempotent_execution_prevents_duplicate` | Triggering the same action for the same source event twice results in only one `action_execution` record |
| `test_escalation_step_with_delay` | An escalation step with `delay_seconds = 60` dispatches the next action with the correct queue delay |
| `test_action_requiring_confirmation_pauses` | An action with `execution_mode = requires_confirmation` stays in `pending` status until the confirm endpoint is called |
| `test_workflow_fails_on_step_failure_without_fallback` | A workflow step failure without `fallback_action` marks the entire workflow execution as `failed` |
| `test_escalation_fallback_executes_on_step_failure` | A step failure with `fallback_action` configured triggers the fallback before failing the workflow |
| `test_manual_workflow_trigger_creates_execution` | POSTing to the manual trigger endpoint creates a `workflow_executions` record and dispatches the job |
| `test_cancelled_execution_does_not_proceed` | Cancelling a `pending` execution sets status to `cancelled` and no job runs |
| `test_usage_event_emitted_per_workflow_execution` | Each `workflow_executions` creation emits an `incident_workflows` usage event via `RecordUsageEvent` |
| `test_tenant_template_takes_precedence_over_system` | When a tenant has a custom template with the same code, it is used instead of the system-wide template |
| `test_action_execution_logs_record_full_trace` | Every execution attempt produces at least one `action_execution_logs` entry |
| `test_webhook_action_posts_to_configured_url` | A `call_webhook` action sends an HTTP POST and stores the response in `response_json` |
