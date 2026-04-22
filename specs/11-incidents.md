# Incidents

## 1. Purpose

Manage the full lifecycle of operational incidents — from creation (automated via decision engine or manual by operators) through assignment, evidence gathering, resolution, and closure. Incidents are the primary operational artifact that operators interact with, aggregating events, evidence, and actions into a single auditable workflow.

## 2. Responsibilities

- Create incidents from decision engine outcomes or manual operator input
- Prevent duplicate incidents for the same open case on the same asset/driver
- Manage incident status lifecycle (open → in_review → escalated → resolved → closed)
- Assign incidents to users, teams, queues, or automated handlers
- Collect and attach evidence from upstream modules (media, AI explanations, telemetry) and manual uploads
- Maintain a narrative timeline recording every state change, comment, and action
- Link multiple related events to a single incident
- Record resolution details including root cause and corrective actions
- Support reclassification (change type/priority) without losing history
- Enforce soft deletes — incidents are never hard-deleted

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Decisions | `Decision` with `INCIDENT` or `ESCALATE` outcome | `DecisionMade` domain event |
| Operators | Manual incident creation | Inertia page / API |
| Context | `EventContextSnapshot`, `EventMediaContext` (evidence sources) | Eloquent relationship |
| AI | `AIEventEvaluation` explanations (attached as evidence) | Eloquent relationship |
| Operators | Comments, manual evidence uploads | Inertia page / API |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Automation | `Incident` requiring automated response | `IncidentCreated` domain event |
| Notifications | Incident alerts to assigned operators | `IncidentCreated`, `IncidentStatusChanged` domain events |
| Frontend | Real-time incident updates | `IncidentCreatedBroadcast`, `IncidentUpdatedBroadcast` on `private-accounts.{teamId}` |
| Tenancy | `incident_workflows` usage event | `RecordUsageEvent` action |
| Audit | Full incident timeline and resolution history | `incident_timelines`, `incident_resolutions` tables |

## 4. Entities

### 4.1 Incident Types (`incident_types`)

Catalog of incident categories. Seeded at deployment.

```php
Schema::create('incident_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->foreignId('default_priority_id')->nullable()->constrained('incident_priorities')->nullOnDelete();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Seed values**: `panic_emergency`, `collision`, `camera_obstructed`, `route_deviation`, `geofence_breach`, `driver_fatigue`, `suspicious_stop`

### 4.2 Incident Statuses (`incident_statuses`)

Lifecycle states for incidents. Seeded at deployment.

```php
Schema::create('incident_statuses', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->boolean('is_terminal')->default(false);
    $table->unsignedTinyInteger('sort_order');
    $table->timestamps();
});
```

**Seed values**: `open` (1), `in_review` (2), `escalated` (3), `resolved` (4, terminal), `closed` (5, terminal), `false_positive` (6, terminal), `cancelled` (7, terminal)

### 4.3 Incident Priorities (`incident_priorities`)

Priority levels with SLA targets. Seeded at deployment.

```php
Schema::create('incident_priorities', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->unsignedTinyInteger('level');
    $table->unsignedInteger('sla_seconds')->nullable();
    $table->string('color')->nullable();
    $table->timestamps();
});
```

**Seed values**: `low` (level 1), `medium` (level 2), `high` (level 3), `critical` (level 4)

### 4.4 Incidents (`incidents`)

The core incident record.

```php
Schema::create('incidents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('incident_type_id')->constrained('incident_types');
    $table->foreignId('incident_status_id')->constrained('incident_statuses');
    $table->foreignId('incident_priority_id')->constrained('incident_priorities');
    $table->string('source_type'); // ai_decision, normalized_event, raw_event, manual, escalation_policy, system_rule
    $table->unsignedBigInteger('source_reference_id')->nullable();
    $table->foreignId('related_event_id')->nullable()->constrained('normalized_events')->nullOnDelete();
    $table->foreignId('related_decision_id')->nullable()->constrained('decisions')->nullOnDelete();
    $table->unsignedBigInteger('asset_id')->nullable();
    $table->unsignedBigInteger('driver_id')->nullable();
    $table->string('title');
    $table->text('summary');
    $table->text('description')->nullable();
    $table->timestamp('opened_at');
    $table->timestamp('resolved_at')->nullable();
    $table->timestamp('closed_at')->nullable();
    $table->timestamp('false_positive_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();
    $table->string('created_by_type'); // system, user
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['team_id', 'incident_status_id']);
    $table->index(['team_id', 'opened_at']);
    $table->index('asset_id');
    $table->index('driver_id');
});
```

**Enum `IncidentSourceType`**: `AiDecision`, `NormalizedEvent`, `RawEvent`, `Manual`, `EscalationPolicy`, `SystemRule`

**Enum `IncidentCreatorType`**: `System`, `User`

### 4.5 Incident Timelines (`incident_timelines`)

Narrative history of everything that happens to an incident.

```php
Schema::create('incident_timelines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
    $table->string('entry_type'); // created, status_changed, priority_changed, assigned, escalated, comment_added, evidence_added, action_executed, resolved, closed, reopened, reclassified
    $table->string('actor_type'); // system, user, ai, automation
    $table->unsignedBigInteger('actor_id')->nullable();
    $table->string('title');
    $table->text('description')->nullable();
    $table->jsonb('payload_json')->nullable();
    $table->timestamp('occurred_at');
    $table->timestamps();
});
```

**Enum `TimelineEntryType`**: `Created`, `StatusChanged`, `PriorityChanged`, `Assigned`, `Escalated`, `CommentAdded`, `EvidenceAdded`, `ActionExecuted`, `Resolved`, `Closed`, `Reopened`, `Reclassified`

**Enum `TimelineActorType`**: `System`, `User`, `Ai`, `Automation`

### 4.6 Incident Evidence (`incident_evidence`)

Evidence items attached to an incident from various sources.

```php
Schema::create('incident_evidence', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
    $table->string('evidence_type'); // image, video, audio, document, event_snapshot, telemetry_snapshot, ai_explanation, external_file
    $table->string('source_type'); // event_context, event_media, raw_event, normalized_event, ai_evaluation, manual_upload, external_provider
    $table->unsignedBigInteger('source_reference_id')->nullable();
    $table->string('title')->nullable();
    $table->text('description')->nullable();
    $table->string('file_url')->nullable();
    $table->string('storage_path')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->string('added_by_type'); // system, user
    $table->unsignedBigInteger('added_by_id')->nullable();
    $table->timestamps();
});
```

**Enum `EvidenceType`**: `Image`, `Video`, `Audio`, `Document`, `EventSnapshot`, `TelemetrySnapshot`, `AiExplanation`, `ExternalFile`

**Enum `EvidenceSourceType`**: `EventContext`, `EventMedia`, `RawEvent`, `NormalizedEvent`, `AiEvaluation`, `ManualUpload`, `ExternalProvider`

### 4.7 Incident Assignments (`incident_assignments`)

Tracks who is responsible for an incident at any point in time.

```php
Schema::create('incident_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
    $table->string('assigned_to_type'); // user, team, queue, automated_handler
    $table->unsignedBigInteger('assigned_to_id');
    $table->string('role')->nullable();
    $table->timestamp('assigned_at');
    $table->timestamp('unassigned_at')->nullable();
    $table->string('assigned_by_type'); // system, user
    $table->unsignedBigInteger('assigned_by_id')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `AssigneeType`**: `User`, `Team`, `Queue`, `AutomatedHandler`

### 4.8 Incident Comments (`incident_comments`)

User comments on incidents with visibility controls.

```php
Schema::create('incident_comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('comment');
    $table->string('visibility'); // internal, tenant_visible, audit_only
    $table->timestamps();
});
```

**Enum `CommentVisibility`**: `Internal`, `TenantVisible`, `AuditOnly`

### 4.9 Incident Event Links (`incident_event_links`)

Many-to-one relationship linking normalized events to incidents.

```php
Schema::create('incident_event_links', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->string('relation_type'); // root_trigger, supporting_event, repeated_signal, followup_event
    $table->timestamps();

    $table->unique(['incident_id', 'normalized_event_id']);
});
```

**Enum `EventRelationType`**: `RootTrigger`, `SupportingEvent`, `RepeatedSignal`, `FollowupEvent`

### 4.10 Incident Resolutions (`incident_resolutions`)

Final resolution details when an incident is resolved or closed.

```php
Schema::create('incident_resolutions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('incident_id')->unique()->constrained('incidents')->cascadeOnDelete();
    $table->string('resolution_code'); // handled_successfully, false_positive, operator_confirmed_safe, escalated_externally, unresolved_closed, duplicate_incident
    $table->text('resolution_summary');
    $table->string('resolved_by_type'); // system, user
    $table->unsignedBigInteger('resolved_by_id')->nullable();
    $table->text('root_cause')->nullable();
    $table->text('corrective_action')->nullable();
    $table->text('preventive_action')->nullable();
    $table->timestamp('resolved_at');
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `ResolutionCode`**: `HandledSuccessfully`, `FalsePositive`, `OperatorConfirmedSafe`, `EscalatedExternally`, `UnresolvedClosed`, `DuplicateIncident`

## 5. Services / Actions

### 5.1 `CreateIncidentFromEvent`

**Path**: `app/Domains/Incidents/Actions/CreateIncidentFromEvent.php`

```php
public function execute(Decision $decision, NormalizedEvent $event): Incident
```

- Checks for existing open incidents on the same asset/driver to prevent duplicates
- Maps event type to incident type, decision priority to incident priority
- Creates `Incident` with `source_type = ai_decision`, links decision and event
- Creates initial `IncidentTimeline` entry with `entry_type = created`
- Links the triggering event via `IncidentEventLink` with `relation_type = root_trigger`
- Auto-attaches available evidence from event context and AI explanation
- Records `incident_workflows` usage event via `RecordUsageEvent`
- Dispatches `IncidentCreated` domain event

### 5.2 `AssignIncident`

**Path**: `app/Domains/Incidents/Actions/AssignIncident.php`

```php
public function execute(Incident $incident, string $assignToType, int $assignToId, ?string $role): IncidentAssignment
```

- Creates `IncidentAssignment` record with `assigned_at = now()`
- Unassigns previous active assignment (sets `unassigned_at`) if applicable
- Creates `IncidentTimeline` entry with `entry_type = assigned`
- Dispatches `IncidentAssigned` domain event

### 5.3 `AddIncidentEvidence`

**Path**: `app/Domains/Incidents/Actions/AddIncidentEvidence.php`

```php
public function execute(
    Incident $incident,
    string $evidenceType,
    string $sourceType,
    ?string $filePath,
    ?array $metadata,
): IncidentEvidence
```

- For manual uploads, stores the file in RustFS via `ObjectStorage` contract and records `storage_path`
- Creates `IncidentEvidence` record
- Creates `IncidentTimeline` entry with `entry_type = evidence_added`

### 5.4 `CloseIncident`

**Path**: `app/Domains/Incidents/Actions/CloseIncident.php`

```php
public function execute(Incident $incident, string $resolutionCode, string $summary, ?User $user): IncidentResolution
```

- Creates `IncidentResolution` record with resolution details
- Updates incident status to `resolved` or `closed` (based on resolution code)
- Sets `resolved_at` and/or `closed_at` timestamps
- Creates `IncidentTimeline` entries for `resolved` and `closed`
- Dispatches `IncidentResolved` and `IncidentClosed` domain events

### 5.5 `ReclassifyIncident`

**Path**: `app/Domains/Incidents/Actions/ReclassifyIncident.php`

```php
public function execute(Incident $incident, int $newTypeId, ?int $newPriorityId, ?User $user): void
```

- Updates incident type and optionally priority
- Creates `IncidentTimeline` entry with `entry_type = reclassified`
- Preserves the original type/priority in the timeline `payload_json`

### 5.6 `LinkEventToIncident`

**Path**: `app/Domains/Incidents/Actions/LinkEventToIncident.php`

```php
public function execute(Incident $incident, NormalizedEvent $event, string $relationType): IncidentEventLink
```

- Creates `IncidentEventLink` with unique constraint preventing duplicate links
- Creates `IncidentTimeline` entry recording the linked event

## 6. Jobs

### 6.1 `CreateIncidentJob`

- **Queue**: `incidents`
- **Retry**: 2
- **Payload**: `decision_id`
- **Logic**:
  1. Load `Decision` with normalized event and evaluation
  2. Call `CreateIncidentFromEvent::execute()`
  3. Dispatch `AutoAssignIncidentJob` for auto-assignment

### 6.2 `AutoAssignIncidentJob`

- **Queue**: `incidents`
- **Retry**: 1
- **Payload**: `incident_id`
- **Logic**:
  1. Load incident with type and tenant configuration
  2. Resolve assignment target based on tenant's assignment rules (round-robin, least-loaded, or specific queue)
  3. Call `AssignIncident::execute()` if a target is resolved

### 6.3 `UpdateIncidentPriorityJob`

- **Queue**: `incidents`
- **Payload**: `incident_id`, `new_priority_id`
- **Logic**:
  1. Update incident priority
  2. Create `IncidentTimeline` entry with `entry_type = priority_changed`
  3. Dispatch `IncidentStatusChanged` if priority escalation crosses SLA threshold

## 7. Domain Events

| Event | Payload | Dispatched When |
|-------|---------|-----------------|
| `IncidentCreated` | `Incident $incident` | New incident is created (automated or manual) |
| `IncidentStatusChanged` | `Incident $incident, string $previousStatus, string $newStatus` | Incident transitions between statuses |
| `IncidentAssigned` | `Incident $incident, IncidentAssignment $assignment` | Incident is assigned to a user, team, or queue |
| `IncidentResolved` | `Incident $incident, IncidentResolution $resolution` | Incident is resolved with resolution details |
| `IncidentClosed` | `Incident $incident` | Incident is moved to terminal closed state |

## 8. Broadcasting Events

### `IncidentCreatedBroadcast`

- **Channel**: `private-accounts.{teamId}`
- **Trigger**: When `IncidentCreated` domain event fires
- **Payload**:
  ```json
  {
      "incident_id": 78,
      "title": "Panic button activated - Unit 42",
      "priority": "critical",
      "status": "open",
      "asset_id": 15,
      "opened_at": "2026-04-11T14:30:00Z"
  }
  ```

### `IncidentUpdatedBroadcast`

- **Channel**: `private-accounts.{teamId}`
- **Trigger**: When `IncidentStatusChanged`, `IncidentAssigned`, or `IncidentResolved` fires
- **Payload**:
  ```json
  {
      "incident_id": 78,
      "status": "in_review",
      "priority": "critical",
      "assigned_to": "John Doe",
      "updated_at": "2026-04-11T14:35:00Z"
  }
  ```

## 9. APIs / Endpoints

All tenant-scoped routes are prefixed with `/{current_team}`.

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| GET | `/{current_team}/incidents` | `IncidentController@index` | List incidents with filters (status, priority, type, date range) |
| GET | `/{current_team}/incidents/{incident}` | `IncidentController@show` | View incident detail with timeline, evidence, and assignments |
| POST | `/{current_team}/incidents` | `IncidentController@store` | Create a manual incident |
| PUT | `/{current_team}/incidents/{incident}` | `IncidentController@update` | Update incident details |
| POST | `/{current_team}/incidents/{incident}/assign` | `IncidentAssignmentController@store` | Assign incident to user/team |
| POST | `/{current_team}/incidents/{incident}/evidence` | `IncidentEvidenceController@store` | Upload evidence (file via RustFS) |
| POST | `/{current_team}/incidents/{incident}/comments` | `IncidentCommentController@store` | Add comment to incident |
| POST | `/{current_team}/incidents/{incident}/resolve` | `IncidentController@resolve` | Resolve incident with resolution details |
| POST | `/{current_team}/incidents/{incident}/close` | `IncidentController@close` | Close a resolved incident |
| POST | `/{current_team}/incidents/{incident}/reclassify` | `IncidentController@reclassify` | Change incident type/priority |
| POST | `/{current_team}/incidents/{incident}/link-event` | `IncidentEventLinkController@store` | Link a normalized event to incident |

## 10. Business Rules

1. Not every event becomes an incident — only events with `INCIDENT` or `ESCALATE` decision outcomes trigger automated incident creation.
2. Before creating a new incident, check for existing open (non-terminal) incidents on the same asset and/or driver within a configurable time window to avoid duplicates. If a match is found, link the new event to the existing incident instead.
3. Multiple events can link to one incident (many-to-one via `incident_event_links`). This supports incident aggregation where related signals converge.
4. The timeline is the narrative history — every state change, assignment, comment, evidence addition, and action execution is recorded with actor, timestamp, and context.
5. Evidence can come from upstream modules (event media contexts, AI explanations, telemetry snapshots) or manual uploads stored in RustFS via the `ObjectStorage` contract.
6. Incidents use soft deletes — never hard-delete. Deleted incidents remain queryable for audit purposes.
7. Closed or resolved incidents (terminal status) cannot be reopened without an explicit reopen action that creates a new timeline entry and transitions the status back to `open`.
8. Manual incidents created by operators use `source_type = manual` and require at least a title and summary.

## 11. Integration with Other Modules

| Module | Integration Point |
|--------|-------------------|
| **Decisions** | Receives `DecisionMade` domain event with `INCIDENT`/`ESCALATE` outcome to trigger `CreateIncidentJob` |
| **Assets** | References `asset_id` for asset-related incidents; reads asset details for context |
| **Drivers** | References `driver_id` for driver-related incidents; reads driver profile for context |
| **Context** | Reads `EventContextSnapshot` and `EventMediaContext` to auto-attach evidence |
| **AI** | Reads `AIEventEvaluation` explanations to attach as evidence (`ai_explanation` type) |
| **Automation** | `IncidentCreated` triggers automated response workflows |
| **Notifications** | `IncidentCreated`, `IncidentStatusChanged`, `IncidentAssigned` trigger operator notifications |
| **Tenancy** | Emits `incident_workflows` usage event on incident creation |
| **Broadcasting** | `IncidentCreatedBroadcast` and `IncidentUpdatedBroadcast` on `private-accounts.{teamId}` |
| **Audit** | Full incident timeline and resolution history available for audit queries |
| **RustFS** | Manual evidence uploads stored via `ObjectStorage` contract in RustFS |

## 12. Usage Metering

| Meter Code | Unit | When Recorded |
|------------|------|---------------|
| `incident_workflows` | count | 1 per incident created (automated or manual) via `RecordUsageEvent` in `CreateIncidentFromEvent` |

## 13. Technical Considerations

### Duplicate Detection

Before creating an incident, query for existing open incidents matching:
- Same `team_id`
- Same `asset_id` or `driver_id` (if present)
- Non-terminal `incident_status_id`
- `opened_at` within a configurable time window (default: 30 minutes)

If a match is found, link the new event to the existing incident via `LinkEventToIncident` instead of creating a new one.

### Evidence Storage

- Manual uploads are stored in RustFS using the `ObjectStorage` contract
- Storage path convention: `{teamId}/incidents/{incidentId}/evidence/{filename}`
- `file_url` is generated via the RustFS presigned URL mechanism for frontend display
- Upstream evidence (event media, AI explanations) references the source via `source_reference_id` without duplicating files

### Performance

- `incidents` indexed on `(team_id, incident_status_id)` for filtered dashboard queries and `(team_id, opened_at)` for date-range queries
- `asset_id` and `driver_id` indexed for cross-referencing with asset/driver detail pages
- Incident timeline entries are append-only and ordered by `occurred_at` — no updates or deletes
- For high-volume tenants, paginate timeline entries (default: 50 per page)

### Soft Deletes

- `Incident` model uses `SoftDeletes` trait
- All queries include `whereNull('deleted_at')` by default
- Admin-only endpoints can query with `withTrashed()` for audit purposes

### Seeders

```php
// database/seeders/IncidentTypeSeeder.php
$types = [
    ['code' => 'panic_emergency', 'name' => 'Panic Emergency'],
    ['code' => 'collision', 'name' => 'Collision'],
    ['code' => 'camera_obstructed', 'name' => 'Camera Obstructed'],
    ['code' => 'route_deviation', 'name' => 'Route Deviation'],
    ['code' => 'geofence_breach', 'name' => 'Geofence Breach'],
    ['code' => 'driver_fatigue', 'name' => 'Driver Fatigue'],
    ['code' => 'suspicious_stop', 'name' => 'Suspicious Stop'],
];

// database/seeders/IncidentStatusSeeder.php
$statuses = [
    ['code' => 'open', 'name' => 'Open', 'is_terminal' => false, 'sort_order' => 1],
    ['code' => 'in_review', 'name' => 'In Review', 'is_terminal' => false, 'sort_order' => 2],
    ['code' => 'escalated', 'name' => 'Escalated', 'is_terminal' => false, 'sort_order' => 3],
    ['code' => 'resolved', 'name' => 'Resolved', 'is_terminal' => true, 'sort_order' => 4],
    ['code' => 'closed', 'name' => 'Closed', 'is_terminal' => true, 'sort_order' => 5],
    ['code' => 'false_positive', 'name' => 'False Positive', 'is_terminal' => true, 'sort_order' => 6],
    ['code' => 'cancelled', 'name' => 'Cancelled', 'is_terminal' => true, 'sort_order' => 7],
];

// database/seeders/IncidentPrioritySeeder.php
$priorities = [
    ['code' => 'low', 'name' => 'Low', 'level' => 1, 'sla_seconds' => null, 'color' => '#6B7280'],
    ['code' => 'medium', 'name' => 'Medium', 'level' => 2, 'sla_seconds' => 3600, 'color' => '#F59E0B'],
    ['code' => 'high', 'name' => 'High', 'level' => 3, 'sla_seconds' => 1800, 'color' => '#EF4444'],
    ['code' => 'critical', 'name' => 'Critical', 'level' => 4, 'sla_seconds' => 300, 'color' => '#991B1B'],
];
```

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_incident_created_from_incident_decision` | `DecisionMade` with `INCIDENT` outcome creates an incident with correct type, priority, and linked event |
| `test_duplicate_incident_not_created_for_same_open_case` | Second event on same asset within time window links to existing incident instead of creating a new one |
| `test_incident_timeline_records_all_state_changes` | Status change, assignment, evidence addition, and resolution each produce timeline entries |
| `test_evidence_attached_from_event_media` | Event media context is auto-attached as incident evidence on creation |
| `test_incident_assignment_tracks_ownership` | `AssignIncident` creates assignment record and unassigns previous assignee |
| `test_incident_resolution_records_details` | `CloseIncident` creates resolution with code, summary, root cause, and corrective action |
| `test_event_link_prevents_duplicate_relations` | Linking the same event to the same incident twice violates unique constraint |
| `test_incident_created_broadcasts_to_tenant_channel` | `IncidentCreatedBroadcast` is dispatched to `private-accounts.{teamId}` |
| `test_manual_incident_requires_title_and_summary` | Manual incident creation without title or summary fails validation |
| `test_closed_incident_cannot_be_modified_without_reopen` | Attempting to assign or add evidence to a terminal-status incident is rejected |
| `test_soft_delete_preserves_incident_for_audit` | Soft-deleted incident is excluded from default queries but accessible via `withTrashed()` |
| `test_incident_workflows_usage_event_recorded` | `RecordUsageEvent` is called with `incident_workflows` meter on incident creation |
| `test_incident_scoped_to_tenant` | Incidents are filtered by `team_id` via `BelongsToTenant` trait |
| `test_reclassify_preserves_original_type_in_timeline` | Reclassification creates timeline entry with original type/priority in `payload_json` |
