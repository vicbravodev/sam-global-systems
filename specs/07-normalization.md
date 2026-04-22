# Normalization

## 1. Purpose

Transform raw events from multiple providers into a canonical internal event format, establishing SAM's universal event language independent of any provider. The Normalization module ensures that every downstream module (Context, AI, Decisions, Incidents) works with a consistent, well-typed event structure regardless of the original provider's data format.

## 2. Responsibilities

- Map external provider event types to SAM's canonical event type taxonomy
- Resolve event category and severity from mapping rules or type defaults
- Produce exactly one `NormalizedEvent` per valid `RawEvent` (1:1 relationship)
- Resolve asset and driver references from external identifiers (best-effort)
- Mark unmapped events without failing the pipeline
- Guarantee idempotent normalization (re-normalizing updates, never duplicates)
- Maintain a configuration-driven mapping rules system that supports new providers without code changes
- Provide seed data for common event types, categories, and severities

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Ingestion module | `RawEvent` model (via `raw_event_id`) | `NormalizeEventJob` dispatched to `normalization` queue |
| Admin | Mapping rule configuration | Inertia pages / API |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Context module | `NormalizedEvent` model (with resolved type, category, severity) | `EventNormalized` domain event → triggers `EnrichContextJob` |
| Admin UI | Unmapped event alerts | `EventUnmapped` domain event |

## 4. Entities

### 4.1 Event Types (`event_types`)

SAM's canonical taxonomy of event types. Every normalized event maps to exactly one event type.

```php
Schema::create('event_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->foreignId('category_id')->constrained('event_categories')->cascadeOnDelete();
    $table->foreignId('default_severity_id')->nullable()->constrained('event_severities')->nullOnDelete();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 4.2 Event Categories (`event_categories`)

High-level grouping for event types.

```php
Schema::create('event_categories', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->timestamps();
});
```

**Seed data**:

| Code | Name |
|------|------|
| `safety` | Safety |
| `emergency` | Emergency |
| `compliance` | Compliance |
| `operational` | Operational |
| `maintenance` | Maintenance |

### 4.3 Event Severities (`event_severities`)

Standardized severity levels with SLA implications.

```php
Schema::create('event_severities', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('label');
    $table->unsignedTinyInteger('level'); // 1=low, 2=medium, 3=high, 4=critical
    $table->string('color')->nullable();
    $table->unsignedInteger('response_sla_seconds')->nullable();
    $table->timestamps();
});
```

**Seed data**:

| Code | Label | Level | Color | Response SLA |
|------|-------|-------|-------|-------------|
| `low` | Low | 1 | `#22c55e` | `null` |
| `medium` | Medium | 2 | `#f59e0b` | 3600 (1h) |
| `high` | High | 3 | `#f97316` | 900 (15m) |
| `critical` | Critical | 4 | `#ef4444` | 300 (5m) |

### 4.4 Event Mapping Rules (`event_mapping_rules`)

Configuration-driven rules that map external provider event types to SAM's canonical types. Supports conditional matching for providers that use the same event type string with different semantics.

```php
Schema::create('event_mapping_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
    $table->string('external_event_type');
    $table->jsonb('external_conditions_json')->nullable();
    $table->foreignId('mapped_event_type_id')->constrained('event_types')->cascadeOnDelete();
    $table->foreignId('mapped_category_id')->nullable()->constrained('event_categories')->nullOnDelete();
    $table->foreignId('mapped_severity_id')->nullable()->constrained('event_severities')->nullOnDelete();
    $table->unsignedTinyInteger('priority')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['provider_id', 'external_event_type']);
});
```

### 4.5 Normalized Events (`normalized_events`)

The canonical representation of every event in SAM. One-to-one with `raw_events`.

```php
Schema::create('normalized_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('raw_event_id')->unique()->constrained('raw_events')->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
    $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
    $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
    $table->foreignId('event_category_id')->constrained('event_categories')->cascadeOnDelete();
    $table->foreignId('event_severity_id')->constrained('event_severities')->cascadeOnDelete();
    $table->timestamp('occurred_at');
    $table->timestamp('processed_at');
    $table->jsonb('payload_normalized_json');
    $table->jsonb('context_json')->nullable();
    $table->string('status')->default('normalized'); // normalized, enrichment_pending, enriched, failed, unmapped
    $table->timestamps();

    $table->index(['team_id', 'occurred_at']);
    $table->index(['team_id', 'event_type_id']);
    $table->index(['asset_id', 'occurred_at']);
});
```

**Enum `NormalizedEventStatus`**: `Normalized`, `EnrichmentPending`, `Enriched`, `Failed`, `Unmapped`

### Seed Data: Common Event Types

| Code | Name | Category | Default Severity |
|------|------|----------|-----------------|
| `panic_button` | Panic Button | emergency | critical |
| `harsh_braking` | Harsh Braking | safety | medium |
| `collision` | Collision | emergency | critical |
| `camera_obstructed` | Camera Obstructed | compliance | high |
| `speeding` | Speeding | safety | medium |
| `geofence_exit` | Geofence Exit | operational | low |
| `geofence_entry` | Geofence Entry | operational | low |
| `driver_fatigue` | Driver Fatigue | safety | high |
| `driver_distraction` | Driver Distraction | safety | high |
| `vehicle_idle` | Vehicle Idle | operational | low |
| `device_offline` | Device Offline | maintenance | medium |
| `tampering` | Tampering | compliance | critical |

## 5. Services / Actions

### 5.1 `NormalizeRawEvent`

**Path**: `app/Domains/Normalization/Actions/NormalizeRawEvent.php`

```php
public function execute(RawEvent $rawEvent): NormalizedEvent
```

- Extract `event_type_raw` and `provider_id` from the `RawEvent`
- Call `MapExternalEventType` to find the matching mapping rule
- If no rule found: create `NormalizedEvent` with status `unmapped`, dispatch `EventUnmapped`, return early
- Call `ResolveEventSeverity` to determine final severity (rule override → type default)
- Attempt to resolve `asset_id` from external identifiers in `payload_json` (best-effort, nullable)
- Attempt to resolve `driver_id` from external identifiers in `payload_json` (best-effort, nullable)
- Build `payload_normalized_json` by extracting canonical fields from the raw payload
- Use `updateOrCreate` on `raw_event_id` to guarantee idempotency
- Set `processed_at` to `now()`, `occurred_at` from `RawEvent.occurred_at` or `received_at`
- Dispatch `EventNormalized` domain event
- Update `RawEvent.status` to `processed`

### 5.2 `MapExternalEventType`

**Path**: `app/Domains/Normalization/Actions/MapExternalEventType.php`

```php
public function execute(
    int $providerId,
    string $externalEventType,
    ?array $conditions = null,
): ?EventMappingRule
```

- Query `event_mapping_rules` where `provider_id`, `external_event_type` match and `is_active = true`
- If `$conditions` provided, evaluate `external_conditions_json` against them (JSON path matching)
- Order by `priority` descending, return the first match
- Return `null` if no matching rule exists

### 5.3 `ResolveEventSeverity`

**Path**: `app/Domains/Normalization/Actions/ResolveEventSeverity.php`

```php
public function execute(
    EventMappingRule $rule,
    EventType $type,
): EventSeverity
```

- If `$rule->mapped_severity_id` is set, use the rule's override severity
- Otherwise, fall back to `$type->default_severity_id`
- If neither is set, default to the `medium` severity
- Return the resolved `EventSeverity` model

## 6. Jobs

### 6.1 `NormalizeEventJob`

- **Queue**: `normalization`
- **Retry**: 3
- **Backoff**: `[10, 30, 60]`
- **Logic**:
  1. Load `RawEvent` by ID
  2. Validate that `RawEvent.status` is `pending_processing` or `processing` (guard against stale dispatches)
  3. Call `NormalizeRawEvent::execute($rawEvent)`
  4. On failure: update `RawEvent.status` to `failed`, log the error

## 7. Domain Events

| Event | Payload | Dispatched When |
|-------|---------|-----------------|
| `EventNormalized` | `NormalizedEvent $normalizedEvent` | A raw event is successfully normalized |
| `EventUnmapped` | `RawEvent $rawEvent, string $externalEventType, int $providerId` | No mapping rule exists for the event type |

## 8. Broadcasting Events

None. The Normalization module does not broadcast to frontend clients. User-facing broadcasts occur in downstream modules (Incidents, Notifications).

## 9. APIs / Endpoints

Endpoints are registered in the `NormalizationServiceProvider` or existing route files. All tenant-scoped routes are prefixed with `/{current_team}`.

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| GET | `/{current_team}/events/normalized` | `NormalizedEventController@index` | List normalized events (paginated, filterable) |
| GET | `/{current_team}/events/normalized/{normalizedEvent}` | `NormalizedEventController@show` | View single normalized event with raw event details |
| GET | `/{current_team}/normalization/mapping-rules` | `MappingRuleController@index` | List mapping rules per provider |
| POST | `/{current_team}/normalization/mapping-rules` | `MappingRuleController@store` | Create a new mapping rule |
| PUT | `/{current_team}/normalization/mapping-rules/{mappingRule}` | `MappingRuleController@update` | Update a mapping rule |
| DELETE | `/{current_team}/normalization/mapping-rules/{mappingRule}` | `MappingRuleController@destroy` | Delete a mapping rule |
| GET | `/{current_team}/normalization/event-types` | `EventTypeController@index` | List canonical event types |
| GET | `/{current_team}/normalization/unmapped` | `NormalizedEventController@unmapped` | List unmapped events for triage |

## 10. Business Rules

1. **Every valid RawEvent MUST attempt normalization** — no raw event should remain in `pending_processing` indefinitely. Failed normalization is an explicit terminal state.
2. **A RawEvent produces exactly one NormalizedEvent** — the `raw_event_id` column on `normalized_events` has a unique constraint enforcing a strict 1:1 relationship.
3. **If no mapping rule exists, mark as `unmapped` but do NOT fail the pipeline** — unmapped events are visible in the admin UI for manual triage and rule creation. They do not block other events.
4. **Normalization is idempotent** — re-normalizing the same raw event uses `updateOrCreate` on `raw_event_id`, updating the existing `NormalizedEvent` rather than creating a duplicate.
5. **The system MUST support adding new providers without code changes** — mapping rules are configuration-driven data rows, not hard-coded logic. New providers only require new `event_mapping_rules` entries.
6. **Asset and driver resolution is best-effort at this stage** — both `asset_id` and `driver_id` are nullable on `normalized_events`. Resolution uses external identifiers in the raw payload to look up existing Assets and Drivers. Missing matches are not errors.
7. **Mapping rule priority determines winner** — when multiple rules match the same `(provider_id, external_event_type)`, the rule with the highest `priority` value wins.
8. **Severity resolution has a clear cascade** — mapping rule override → event type default → fallback to `medium`.

## 11. Integration with Other Modules

| Module | Integration Point |
|--------|-------------------|
| **Ingestion** | Consumes `RawEvent` models dispatched via `ProcessRawEventJob`. Reads `payload_json`, `event_type_raw`, `provider_id`. Updates `RawEvent.status` to `processed` on success. |
| **Context** | Produces `NormalizedEvent` models. Dispatches `EventNormalized` domain event, which triggers the Context module's `EnrichContextJob`. |
| **Assets** | Queries `assets` table to resolve `asset_id` from external device identifiers found in raw event payloads (e.g., IMEI, serial number, provider device ID). |
| **Drivers** | Queries `drivers` table to resolve `driver_id` from external driver identifiers found in raw event payloads (e.g., driver code, provider driver ID). |
| **Integrations** | Reads `integration_providers` for provider metadata and mapping rule association. |
| **Audit** | `EventNormalized` and `EventUnmapped` events are available for audit logging. |

## 12. Usage Metering

None directly. Normalization is an internal pipeline step that does not produce billable actions. Metering occurs at the AI evaluation step where compute resources are consumed.

## 13. Technical Considerations

### Performance

- `normalized_events` will grow at the same rate as `raw_events`. The `(team_id, occurred_at)` index supports dashboard and timeline queries.
- `(team_id, event_type_id)` index supports filtering by event type across a tenant's history.
- `(asset_id, occurred_at)` index supports asset-centric event timelines.
- Mapping rule lookups are fast due to the `(provider_id, external_event_type)` composite index.
- Cache frequently-used mapping rules in Valkey with 5-minute TTL keyed by `provider_id:external_event_type`.

### Idempotency

- `NormalizeRawEvent` uses `updateOrCreate` keyed on `raw_event_id` (which has a unique constraint). Re-normalizing a raw event safely overwrites the existing normalized event.
- This supports reprocessing workflows where raw events need to be re-normalized after mapping rules change.

### Mapping Rule Conditions

The `external_conditions_json` column supports conditional matching for providers that reuse event type strings:

```json
{
    "payload.sub_type": "fatigue",
    "payload.severity": "high"
}
```

Conditions are evaluated as AND logic — all conditions must match for the rule to apply.

### Seed Data Migration

Event categories, severities, and common event types are seeded via a dedicated seeder class (`NormalizationSeeder`) that is idempotent (uses `firstOrCreate`). This seeder runs during initial deployment and can be re-run safely.

### Asset/Driver Resolution Strategy

Resolution follows a priority chain within the raw payload:
1. Provider-specific device ID field (configured per provider in mapping rules)
2. IMEI or serial number
3. External asset/driver code

The resolution logic lives in the `NormalizeRawEvent` action and delegates to the Assets and Drivers modules' query interfaces.

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_raw_event_normalizes_to_correct_type_and_severity` | A raw event with a known mapping rule produces a `NormalizedEvent` with the expected `event_type_id` and `event_severity_id` |
| `test_unmapped_event_type_creates_unmapped_normalized_event` | A raw event with no matching mapping rule creates a `NormalizedEvent` with status `unmapped` |
| `test_normalization_resolves_asset_from_external_id` | A raw event payload containing a known device IMEI produces a `NormalizedEvent` with the correct `asset_id` |
| `test_normalization_resolves_driver_from_external_id` | A raw event payload containing a known driver code produces a `NormalizedEvent` with the correct `driver_id` |
| `test_duplicate_normalization_does_not_create_second_record` | Calling `NormalizeRawEvent` twice for the same `RawEvent` results in exactly one `NormalizedEvent` row |
| `test_mapping_rule_with_conditions_applies_correctly` | A mapping rule with `external_conditions_json` only matches when payload conditions are met |
| `test_mapping_rule_priority_selects_highest` | When two rules match the same provider/event type, the rule with the higher `priority` value is selected |
| `test_severity_falls_back_to_type_default` | When the mapping rule has no `mapped_severity_id`, the event type's `default_severity_id` is used |
| `test_severity_falls_back_to_medium_when_no_default` | When neither mapping rule nor event type defines severity, `medium` is used |
| `test_event_normalized_domain_event_dispatched` | `EventNormalized` fires after successful normalization |
| `test_event_unmapped_domain_event_dispatched` | `EventUnmapped` fires when no mapping rule matches |
| `test_raw_event_status_updated_to_processed` | After successful normalization, the source `RawEvent` has status `processed` |
| `test_normalized_events_scoped_to_team` | Listing normalized events only returns results for the authenticated team |
| `test_seed_data_creates_categories_and_severities` | Running the `NormalizationSeeder` creates all expected categories, severities, and event types |
