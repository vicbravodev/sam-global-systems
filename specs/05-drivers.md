# Drivers

## 1. Purpose

Represent and manage persons associated with monitored assets (primarily drivers), enabling contextual analysis of events by knowing who was responsible for an asset at any given point in time. Drivers are synced from external providers and enriched with assignment history, contact information, documents, and risk profiles.

## 2. Responsibilities

- Store and manage driver records discovered from integration sync.
- Maintain temporal driver-to-asset assignments (who was driving what, and when).
- Track driver status changes over time.
- Store driver contact information and emergency contacts for escalation.
- Manage driver documents (licenses, certifications) with expiration tracking.
- Maintain cross-references to external provider IDs.
- Calculate and maintain driver risk profiles from incident and event data.
- Resolve which driver was responsible for a given asset at a specific timestamp.

## 3. Inputs / Outputs

### Inputs

| Source | Data |
|--------|------|
| `App\Domains\Integrations` | Driver data from `SyncIntegrationJob` and webhook processing |
| `App\Domains\Ingestion` | Assignment changes from normalized events |
| `App\Domains\Incidents` | Incident counts and harsh event data for risk profile calculation |

### Outputs

| Target | Data |
|--------|------|
| `App\Domains\Context` | Driver context for event enrichment (who was driving at event time) |
| `App\Domains\Incidents` | Driver details and escalation contacts for incident management |
| `App\Domains\Notifications` | Emergency and supervisor contact information via `GetEscalationContactsForDriver` |
| `App\Domains\AI` | Driver risk profile as context for AI evaluation |

## 4. Entities

### 4.1 `drivers`

The core driver record, always associated with a tenant.

```php
Schema::create('drivers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('external_primary_id')->nullable();
    $table->string('first_name');
    $table->string('last_name');
    $table->string('full_name');
    $table->string('employee_code')->nullable();
    $table->string('status')->default('active'); // enum: active, off_duty, unavailable, suspended, under_review
    $table->json('metadata_json')->nullable();
    $table->timestamp('first_seen_at')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['team_id', 'status']);
});
```

**Enum `DriverStatus`**: `Active`, `OffDuty`, `Unavailable`, `Suspended`, `UnderReview`

### 4.2 `driver_assignments`

Temporal association between a driver and an asset. Supports historical lookups.

```php
Schema::create('driver_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
    $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
    $table->string('assignment_type'); // enum: primary_driver, secondary_driver, temporary_operator, responsible_party
    $table->timestamp('started_at');
    $table->timestamp('ended_at')->nullable();
    $table->string('source'); // enum: integration, manual
    $table->string('source_reference_id')->nullable();
    $table->json('metadata_json')->nullable();
    $table->timestamps();

    $table->index(['asset_id', 'started_at', 'ended_at']);
    $table->index(['driver_id', 'started_at']);
});
```

**Enum `AssignmentType`**: `PrimaryDriver`, `SecondaryDriver`, `TemporaryOperator`, `ResponsibleParty`

**Enum `AssignmentSource`**: `Integration`, `Manual`

### 4.3 `driver_statuses`

Historical status log for a driver with severity levels.

```php
Schema::create('driver_statuses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
    $table->string('status_code');
    $table->string('status_label');
    $table->string('severity'); // enum: low, medium, high, critical
    $table->timestamp('effective_from');
    $table->timestamp('effective_to')->nullable();
    $table->string('source_event_id')->nullable();
    $table->json('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `StatusSeverity`**: `Low`, `Medium`, `High`, `Critical`

### 4.4 `driver_contacts`

Contact information for a driver, including emergency contacts.

```php
Schema::create('driver_contacts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
    $table->string('contact_type'); // enum: mobile_phone, email, emergency_contact, supervisor_contact
    $table->string('label')->nullable();
    $table->string('value');
    $table->boolean('is_primary')->default(false);
    $table->boolean('is_emergency')->default(false);
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();
});
```

**Enum `ContactType`**: `MobilePhone`, `Email`, `EmergencyContact`, `SupervisorContact`

### 4.5 `driver_documents`

Licenses, certifications, and other documents with expiration tracking.

```php
Schema::create('driver_documents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
    $table->string('document_type'); // enum: license, identification, medical_cert, internal_doc, special_permit
    $table->string('document_number')->nullable();
    $table->date('issued_at')->nullable();
    $table->date('expires_at')->nullable();
    $table->string('status')->default('valid'); // enum: valid, expired, pending_renewal
    $table->string('file_url')->nullable();
    $table->json('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `DocumentType`**: `License`, `Identification`, `MedicalCert`, `InternalDoc`, `SpecialPermit`

**Enum `DocumentStatus`**: `Valid`, `Expired`, `PendingRenewal`

### 4.6 `driver_external_references`

Maps a driver to their external ID in each connected provider.

```php
Schema::create('driver_external_references', function (Blueprint $table) {
    $table->id();
    $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
    $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
    $table->string('external_id');
    $table->string('external_type')->nullable();
    $table->json('metadata_json')->nullable();
    $table->timestamp('first_seen_at')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();

    $table->unique(['provider_id', 'external_id']);
});
```

### 4.7 `driver_risk_profiles`

Aggregated risk metrics for a driver, recalculated periodically.

```php
Schema::create('driver_risk_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('driver_id')->unique()->constrained('drivers')->cascadeOnDelete();
    $table->decimal('risk_score', 5, 2)->nullable();
    $table->string('risk_level')->nullable(); // enum: low, medium, high, critical
    $table->unsignedInteger('incidents_count')->default(0);
    $table->unsignedInteger('harsh_events_count')->default(0);
    $table->unsignedInteger('fatigue_flags_count')->default(0);
    $table->timestamp('last_calculated_at')->nullable();
    $table->json('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `RiskLevel`**: `Low`, `Medium`, `High`, `Critical`

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `SyncDriverFromIntegration` | Upsert a driver from integration sync data. Resolves by external reference (provider + external_id), creates new driver if not found, updates existing if found. Dispatches `DriverDiscovered` for new drivers. |
| `AssignDriverToAsset` | Create a temporal assignment between a driver and an asset. Ends any existing active assignment of the same type for the asset. Dispatches `DriverAssigned`. |
| `ResolveDriverForEvent` | Given an `asset_id` and a `timestamp`, find the active driver assignment at that point in time. Returns the driver or `null`. Critical for event attribution in the Context domain. |
| `UpdateDriverStatus` | Transition a driver's status, log it in `driver_statuses`, and dispatch `DriverStatusChanged`. |
| `GetEscalationContactsForDriver` | Return the ordered list of contacts to notify for a given driver, prioritizing emergency and supervisor contacts. Used by the Notifications domain during incident escalation. |

## 6. Jobs

### `SyncDriversFromProviderJob`

- **Queue**: `sync`
- **Retry**: 3 attempts
- **Behaviour**: Fetches driver data from a provider's API for a given `tenant_integration_id`, calls `SyncDriverFromIntegration` for each driver discovered, and creates or updates driver-asset assignments based on provider data.

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `DriverDiscovered` | New driver created from integration sync | `teamId`, `driverId`, `fullName`, `providerCode`, `externalId` |
| `DriverAssigned` | `AssignDriverToAsset` creates a new assignment | `teamId`, `driverId`, `assetId`, `assignmentType`, `startedAt` |
| `DriverStatusChanged` | `UpdateDriverStatus` transitions status | `teamId`, `driverId`, `previousStatus`, `newStatus` |

## 8. Broadcasting Events

No dedicated broadcasting events. Driver changes are communicated to the frontend as part of broader asset and incident broadcasts. If real-time driver status updates become necessary, a `DriverStatusChangedBroadcast` can be added to `private-accounts.{teamId}` following the same pattern as `AssetStatusChangedBroadcast`.

## 9. APIs / Endpoints

All endpoints are protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/api/{current_team}/drivers` | `DriverController@index` | List drivers (filterable by status, search) |
| `GET` | `/api/{current_team}/drivers/{driver}` | `DriverController@show` | Get driver detail with contacts, documents, risk profile, and current assignment |
| `GET` | `/api/{current_team}/drivers/{driver}/assignments` | `DriverController@assignments` | Paginated assignment history for a driver |
| `GET` | `/api/{current_team}/drivers/{driver}/risk-profile` | `DriverController@riskProfile` | Get the driver's current risk profile |
| `PUT` | `/api/{current_team}/drivers/{driver}/contacts` | `DriverController@updateContacts` | Update driver contact information (manual override) |
| `PUT` | `/api/{current_team}/drivers/{driver}/documents` | `DriverController@updateDocuments` | Update driver document records (manual override) |

Drivers cannot be created or deleted via API. They originate from integration sync and are soft-deleted only.

## 10. Business Rules

1. **Tenant isolation** — Every driver has a `team_id`. The `BelongsToTenant` trait enforces scoping.
2. **Temporal assignments** — Assignments have `started_at` and `ended_at`. `ResolveDriverForEvent` queries `WHERE started_at <= :timestamp AND (ended_at IS NULL OR ended_at > :timestamp)` to find the active driver at any historical point.
3. **One active primary driver per asset** — When a new `PrimaryDriver` assignment is created for an asset, any existing active `PrimaryDriver` assignment for that asset is ended (set `ended_at = now()`).
4. **External reference uniqueness** — The combination of `(provider_id, external_id)` in `driver_external_references` is unique. Sync uses this to resolve existing drivers.
5. **Soft delete only** — Drivers are never hard-deleted. Historical assignment data must be preserved.
6. **Risk profile recalculation** — Risk profiles are recalculated periodically (e.g., daily or after incident resolution), not on every event. The `last_calculated_at` timestamp tracks staleness.
7. **Full name derived** — `full_name` is stored as a denormalized field (`first_name` + `last_name`) for query convenience.
8. **Document expiration** — Documents with `expires_at` in the past should be automatically marked `expired`. A scheduled job handles this.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Tenancy** | `team_id` FK. Uses `BelongsToTenant` trait. |
| **Integrations** | `SyncDriverFromIntegration` is called by `SyncIntegrationJob`. `driver_external_references.provider_id` links back to the originating provider. |
| **Assets** | `driver_assignments.asset_id` FK references `assets.id`. `ResolveDriverForEvent` queries assignments to find the driver of a given asset at a timestamp. |
| **Context** | Context domain calls `ResolveDriverForEvent` to enrich normalized events with driver identity. |
| **Incidents** | Incidents reference the driver involved. `GetEscalationContactsForDriver` provides contact data for notifications. |
| **Notifications** | Uses escalation contacts from this domain to determine who to notify during incidents. |
| **AI** | Risk profile data is included as context when the AI agent evaluates events for a driver. |

## 12. Usage Metering

None. Drivers are not a billable dimension. Driver counts may be included in analytics but do not generate usage events via `RecordUsageEvent`.

## 13. Technical Considerations

- **Temporal query performance** — `driver_assignments` queries by time range are frequent and critical. The composite index on `(asset_id, started_at, ended_at)` is essential. For high-volume fleets, consider a materialized view of current assignments.
- **Overlapping assignments** — The system must handle cases where a driver has overlapping assignments (e.g., `PrimaryDriver` on one asset and `ResponsibleParty` on another). The `assignment_type` distinguishes these.
- **Risk profile calculation** — Risk score calculation should be done in a background job, not inline. Aggregate counts from `incidents` and `normalized_events` for the rolling window.
- **Document file storage** — `file_url` references files stored in RustFS via the `ObjectStorage` contract. Path convention: `{team_id}/drivers/{driver_id}/documents/{filename}`.
- **N+1 prevention** — API endpoints should eager-load `contacts`, `documents`, `riskProfile`, and `currentAssignment` (latest open assignment).
- **Sync idempotency** — `SyncDriverFromIntegration` uses `updateOrCreate` keyed on `(provider_id, external_id)` via external references for idempotent sync.
- **Privacy** — Driver personal data (contacts, documents) should be handled according to data protection policies. Consider field-level encryption for sensitive contact values in future iterations.

## 14. Test Scenarios

### Driver Sync

- `test_it_creates_driver_from_integration_sync`
- `test_it_updates_existing_driver_on_duplicate_external_id`
- `test_it_dispatches_driver_discovered_event_for_new_driver`
- `test_it_sets_full_name_from_first_and_last_name`

### Assignments

- `test_it_assigns_driver_to_asset`
- `test_it_ends_existing_primary_assignment_when_new_one_created`
- `test_it_allows_multiple_assignment_types_simultaneously`
- `test_it_resolves_driver_for_event_at_specific_timestamp`
- `test_it_returns_null_when_no_driver_assigned_at_timestamp`
- `test_it_resolves_correct_driver_across_multiple_assignments`

### Status

- `test_it_updates_driver_status_and_logs_history`
- `test_it_dispatches_driver_status_changed_event`

### Contacts

- `test_it_returns_escalation_contacts_in_priority_order`
- `test_it_updates_driver_contacts_via_api`
- `test_it_prioritizes_emergency_contacts_in_escalation`

### Documents

- `test_it_marks_expired_documents_automatically`
- `test_it_updates_driver_documents_via_api`

### Risk Profile

- `test_it_calculates_risk_score_from_incident_data`
- `test_it_updates_risk_level_based_on_score_thresholds`
- `test_it_tracks_last_calculated_at_timestamp`

### Tenant Isolation

- `test_it_scopes_drivers_to_current_team`
- `test_it_cannot_access_another_teams_drivers`

### Soft Delete

- `test_it_soft_deletes_driver`
- `test_it_preserves_assignment_history_after_soft_delete`

### API

- `test_it_lists_drivers_with_filters`
- `test_it_shows_driver_detail_with_related_data`
- `test_it_returns_paginated_assignment_history`
- `test_it_rejects_manual_driver_creation`
