# Context

## 1. Purpose

Enrich each normalized event with operational context — asset state, driver info, location, geofences, recent history, related incidents, and media — to build a comprehensive snapshot for AI analysis. The Context module transforms a bare normalized event into a richly annotated decision-ready package, giving the AI module everything it needs to evaluate the event without making additional queries.

## 2. Responsibilities

- Build immutable context snapshots that capture the operational state at the moment an event occurred
- Resolve geofence context via point-in-polygon and distance-based matching
- Load recent asset event history within configurable time windows
- Find related open incidents for the same asset, driver, or location cluster
- Resolve driver operational context (shift status, recent risk events, compliance flags)
- Attach immediately-available media (snapshots, clips) from the raw event or provider
- Request deferred media from providers when not immediately available (video clips, extended recordings)
- Generate contextual signal flags that summarize the enrichment results for AI consumption
- Build operational context profiles that score risk, priority, and recurrence

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Normalization module | `NormalizedEvent` model (via `normalized_event_id`) | `EventNormalized` domain event → triggers `EnrichContextJob` |
| Assets module | Asset state, telemetry, device info | Eloquent queries |
| Drivers module | Driver profile, shift status, risk history | Eloquent queries |
| Incidents module | Open incidents for same asset/driver | Eloquent queries |
| External providers | Deferred media (video clips, snapshots) | `FetchDeferredEventMediaJob` |
| RustFS | Stored media binaries | `ObjectStorage` contract |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| AI module | `EventContextSnapshot` with full context and signals | `EventContextBuilt` domain event → triggers AI evaluation |
| AI module | `OperationalContextProfile` with risk/priority scores | Eager-loaded with snapshot |
| RustFS | Fetched media binaries (video clips, snapshots) | `ObjectStorage` contract |

## 4. Entities

### 4.1 Event Context Snapshots (`event_context_snapshots`)

The immutable, point-in-time context for a normalized event. Contains denormalized snapshots of all relevant operational state.

```php
Schema::create('event_context_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->unique()->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
    $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
    $table->timestamp('event_occurred_at');
    $table->unsignedTinyInteger('context_version')->default(1);
    $table->jsonb('location_snapshot_json')->nullable();
    $table->jsonb('asset_snapshot_json')->nullable();
    $table->jsonb('driver_snapshot_json')->nullable();
    $table->jsonb('telemetry_snapshot_json')->nullable();
    $table->jsonb('geofence_snapshot_json')->nullable();
    $table->jsonb('incidents_snapshot_json')->nullable();
    $table->jsonb('recent_history_snapshot_json')->nullable();
    $table->jsonb('media_snapshot_json')->nullable();
    $table->jsonb('signals_json')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'event_occurred_at']);
});
```

### 4.2 Geofences (`geofences`)

Team-defined geographic boundaries used for location-based context enrichment.

```php
Schema::create('geofences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('code')->nullable();
    $table->string('geofence_type'); // zone, route, point
    $table->jsonb('geometry_json');
    $table->string('category'); // client_site, risk_zone, border, distribution_center, restricted_route
    $table->boolean('is_active')->default(true);
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'is_active']);
});
```

**Enum `GeofenceType`**: `Zone`, `Route`, `Point`

**Enum `GeofenceCategory`**: `ClientSite`, `RiskZone`, `Border`, `DistributionCenter`, `RestrictedRoute`

### 4.3 Geofence Matches (`geofence_matches`)

Records which geofences an event's location matched during context enrichment.

```php
Schema::create('geofence_matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('geofence_id')->constrained('geofences')->cascadeOnDelete();
    $table->string('match_type'); // inside, outside, entry, exit, near_boundary
    $table->timestamp('matched_at');
    $table->unsignedInteger('distance_meters')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `GeofenceMatchType`**: `Inside`, `Outside`, `Entry`, `Exit`, `NearBoundary`

### 4.4 Event Related Incident Links (`event_related_incident_links`)

Associates a normalized event with related open or recent incidents for correlation analysis.

```php
Schema::create('event_related_incident_links', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
    $table->string('relation_type'); // same_asset_open_incident, same_driver_recent_incident, same_location_cluster, probable_followup, duplicate_operational_case
    $table->decimal('confidence_score', 3, 2)->nullable();
    $table->timestamps();
});
```

**Enum `IncidentRelationType`**: `SameAssetOpenIncident`, `SameDriverRecentIncident`, `SameLocationCluster`, `ProbableFollowup`, `DuplicateOperationalCase`

### 4.5 Event Recent History Snapshots (`event_recent_history_snapshots`)

Pre-computed statistical summary of recent event activity around the same asset, driver, or location.

```php
Schema::create('event_recent_history_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->timestamp('window_start');
    $table->timestamp('window_end');
    $table->unsignedInteger('recent_events_count');
    $table->unsignedInteger('recent_incidents_count');
    $table->unsignedInteger('recent_same_type_count');
    $table->unsignedInteger('recent_high_severity_count');
    $table->jsonb('recent_locations_json')->nullable();
    $table->jsonb('recent_flags_json')->nullable();
    $table->timestamps();
});
```

### 4.6 Event Media Contexts (`event_media_contexts`)

Tracks all media associated with a normalized event — both immediately available and deferred.

```php
Schema::create('event_media_contexts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->string('media_type'); // image, video, audio, snapshot, clip
    $table->string('media_role'); // primary_evidence, supporting_evidence, pre_event_context, post_event_context, driver_facing, road_facing, cabin_audio
    $table->string('media_url')->nullable();
    $table->string('thumbnail_url')->nullable();
    $table->string('storage_path')->nullable();
    $table->unsignedInteger('duration_seconds')->nullable();
    $table->timestamp('captured_at')->nullable();
    $table->timestamp('window_start')->nullable();
    $table->timestamp('window_end')->nullable();
    $table->string('availability_status')->default('not_available'); // available, pending, not_available, expired
    $table->string('retrieval_status')->default('not_requested'); // not_requested, requested, processing, ready, failed
    $table->string('checksum')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();

    $table->index('normalized_event_id');
});
```

**Enum `MediaType`**: `Image`, `Video`, `Audio`, `Snapshot`, `Clip`

**Enum `MediaRole`**: `PrimaryEvidence`, `SupportingEvidence`, `PreEventContext`, `PostEventContext`, `DriverFacing`, `RoadFacing`, `CabinAudio`

**Enum `MediaAvailabilityStatus`**: `Available`, `Pending`, `NotAvailable`, `Expired`

**Enum `MediaRetrievalStatus`**: `NotRequested`, `Requested`, `Processing`, `Ready`, `Failed`

### 4.7 Event Media Requests (`event_media_requests`)

Tracks outbound requests to providers for deferred media retrieval.

```php
Schema::create('event_media_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
    $table->string('request_type'); // fetch_video_clip, fetch_snapshot, fetch_driver_camera, fetch_road_camera, fetch_audio
    $table->timestamp('requested_at');
    $table->string('status')->default('pending'); // pending, sent, processing, completed, failed, expired
    $table->jsonb('response_metadata_json')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

**Enum `MediaRequestType`**: `FetchVideoClip`, `FetchSnapshot`, `FetchDriverCamera`, `FetchRoadCamera`, `FetchAudio`

**Enum `MediaRequestStatus`**: `Pending`, `Sent`, `Processing`, `Completed`, `Failed`, `Expired`

### 4.8 Operational Context Profiles (`operational_context_profiles`)

Derived profile that scores each event's operational significance based on the assembled context.

```php
Schema::create('operational_context_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('normalized_event_id')->unique()->constrained('normalized_events')->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('profile_code')->nullable();
    $table->string('risk_level')->nullable(); // low, medium, high, critical
    $table->decimal('priority_score', 5, 2)->nullable();
    $table->decimal('recurrence_score', 5, 2)->nullable();
    $table->jsonb('contextual_flags_json')->nullable();
    $table->jsonb('summary_json')->nullable();
    $table->timestamps();
});
```

**Enum `RiskLevel`**: `Low`, `Medium`, `High`, `Critical`

## 5. Services / Actions

### 5.1 `BuildEventContext`

**Path**: `app/Domains/Context/Actions/BuildEventContext.php`

```php
public function execute(NormalizedEvent $event): EventContextSnapshot
```

Orchestrates the full context-building pipeline:
1. Load the `NormalizedEvent` with its `asset`, `driver`, `eventType`, and `rawEvent` relationships
2. Call `LoadRecentAssetHistory` if `asset_id` is present
3. Call `ResolveGeofenceContext` if location coordinates are available in the normalized payload
4. Call `GetRelatedOpenIncidents` for the team/asset/driver combination
5. Call `ResolveDriverOperationalContext` if `driver_id` is present
6. Call `AttachImmediateEventMedia` to link available media
7. Build `signals_json` from all collected context
8. Use `updateOrCreate` on `normalized_event_id` for idempotency
9. Call `BuildOperationalContextProfile` with the snapshot
10. Dispatch `EventContextBuilt` domain event

### 5.2 `LoadRecentAssetHistory`

**Path**: `app/Domains/Context/Actions/LoadRecentAssetHistory.php`

```php
public function execute(
    int $assetId,
    DateTimeInterface $before,
    int $windowMinutes = 60,
): array
```

- Query `normalized_events` for `asset_id` within the time window
- Return array with event counts, type breakdown, severity distribution, and location trail

### 5.3 `ResolveGeofenceContext`

**Path**: `app/Domains/Context/Actions/ResolveGeofenceContext.php`

```php
public function execute(
    float $lat,
    float $lng,
    int $teamId,
): Collection
```

- Load active geofences for the team
- For `zone` type: check point-in-polygon containment against `geometry_json`
- For `point` type: compute distance, match if within configured radius
- Return collection of matched `Geofence` models with match type and distance

### 5.4 `GetRelatedOpenIncidents`

**Path**: `app/Domains/Context/Actions/GetRelatedOpenIncidents.php`

```php
public function execute(
    int $teamId,
    ?int $assetId,
    ?int $driverId,
): Collection
```

- Query `incidents` for the team where status is open/in-progress
- Filter by `asset_id` or `driver_id` when provided
- Return collection with relation type classification

### 5.5 `ResolveDriverOperationalContext`

**Path**: `app/Domains/Context/Actions/ResolveDriverOperationalContext.php`

```php
public function execute(NormalizedEvent $event): ?array
```

- Load driver profile and current assignment
- Check recent events for the same driver (risk events, compliance violations)
- Determine if driver is within operating hours
- Return structured array with shift info, recent risk count, compliance flags, or `null` if driver unknown

### 5.6 `AttachImmediateEventMedia`

**Path**: `app/Domains/Context/Actions/AttachImmediateEventMedia.php`

```php
public function execute(NormalizedEvent $event): Collection
```

- Check `raw_event_attachments` for media linked to the raw event
- Check provider API for immediately available media URLs
- Create `EventMediaContext` records with `availability_status = available`
- Store media in RustFS via `ObjectStorage` if binary data is available
- Return collection of created `EventMediaContext` models

### 5.7 `RequestDeferredEventMedia`

**Path**: `app/Domains/Context/Actions/RequestDeferredEventMedia.php`

```php
public function execute(
    NormalizedEvent $event,
    string $requestType,
): EventMediaRequest
```

- Create an `EventMediaRequest` record with status `pending`
- Dispatch `FetchDeferredEventMediaJob` to the `context` queue
- Return the created `EventMediaRequest`

### 5.8 `BuildOperationalContextProfile`

**Path**: `app/Domains/Context/Actions/BuildOperationalContextProfile.php`

```php
public function execute(EventContextSnapshot $snapshot): OperationalContextProfile
```

- Analyze signals, recent history, and incident links to compute `risk_level`
- Calculate `priority_score` from severity, recurrence, and context signals
- Calculate `recurrence_score` from same-type event frequency in recent history
- Build `summary_json` with human-readable context summary
- Use `updateOrCreate` on `normalized_event_id` for idempotency
- Return the created/updated `OperationalContextProfile`

## 6. Jobs

### 6.1 `EnrichContextJob`

- **Queue**: `context`
- **Retry**: 3
- **Backoff**: `[10, 30, 60]`
- **Logic**:
  1. Load `NormalizedEvent` by ID
  2. Call `BuildEventContext::execute($event)`
  3. Update `NormalizedEvent.status` to `enriched` on success
  4. On failure: update status to `failed`, log error details

### 6.2 `FetchDeferredEventMediaJob`

- **Queue**: `context`
- **Retry**: 5
- **Backoff**: `[30, 60, 120, 300, 600]` (aggressive backoff for provider rate limits)
- **Logic**:
  1. Load `EventMediaRequest` by ID
  2. Update status to `sent`
  3. Call provider API to request the media (video clip, snapshot, etc.)
  4. If media is ready: download binary, store in RustFS via `ObjectStorage`, create or update `EventMediaContext` with `availability_status = available` and `retrieval_status = ready`
  5. If media is still processing at provider: re-dispatch with delay (provider indicates processing time)
  6. Update `EventMediaRequest.status` to `completed` or `failed`
  7. Dispatch `EventMediaAvailable` or `EventMediaFailed` domain event
  8. If media arrives after initial context was built, trigger a context refresh (update `media_snapshot_json` and `signals_json`)

## 7. Domain Events

| Event | Payload | Dispatched When |
|-------|---------|-----------------|
| `EventContextBuilt` | `EventContextSnapshot $snapshot, OperationalContextProfile $profile` | Full context enrichment completes |
| `EventMediaAvailable` | `EventMediaContext $media, NormalizedEvent $event` | Deferred media is successfully retrieved and stored |
| `EventMediaFailed` | `EventMediaRequest $request, string $reason` | Media retrieval fails after all retries |

## 8. Broadcasting Events

None. The Context module does not broadcast to frontend clients. The downstream AI module and Incidents module handle user-facing broadcasts when decisions are made.

## 9. APIs / Endpoints

Endpoints are registered in the `ContextServiceProvider` or existing route files. All tenant-scoped routes are prefixed with `/{current_team}`.

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| GET | `/{current_team}/events/{normalizedEvent}/context` | `EventContextController@show` | View full context snapshot for an event |
| GET | `/{current_team}/geofences` | `GeofenceController@index` | List team geofences |
| POST | `/{current_team}/geofences` | `GeofenceController@store` | Create a geofence |
| PUT | `/{current_team}/geofences/{geofence}` | `GeofenceController@update` | Update a geofence |
| DELETE | `/{current_team}/geofences/{geofence}` | `GeofenceController@destroy` | Delete a geofence |
| GET | `/{current_team}/events/{normalizedEvent}/media` | `EventMediaController@index` | List media for an event |
| POST | `/{current_team}/events/{normalizedEvent}/media/request` | `EventMediaController@requestMedia` | Manually request deferred media |

## 10. Business Rules

1. **Context is persisted as an immutable snapshot — not a live query** — once built, the `EventContextSnapshot` represents the world as it was at event time. Downstream consumers read the snapshot, not live state.
2. **Missing context sources do NOT block enrichment** — if the asset module is unavailable, or the driver is unknown, or geofence data is missing, the snapshot is still created with partial context. Every `*_snapshot_json` column is nullable for this reason.
3. **Media can arrive asynchronously** — the system supports re-enrichment when deferred media becomes available. The `context_version` column tracks snapshot revisions. `signals_json` is updated to reflect new media availability.
4. **Recent history windows are configurable per event type** — critical events (collision, panic) may use a wider window (e.g., 120 minutes) while operational events (idle, geofence) use a shorter window (e.g., 30 minutes). Configuration lives in the event type or team settings.
5. **Geofence matching uses point-in-polygon for zones and distance checks for points** — the `geometry_json` column stores GeoJSON-compatible geometry. Zone matching uses ray-casting algorithm. Point matching computes Haversine distance.

## 11. Integration with Other Modules

| Module | Integration Point |
|--------|-------------------|
| **Normalization** | Consumes `NormalizedEvent` models via `EventNormalized` domain event. Reads normalized payload, event type, category, severity, asset/driver references. |
| **Assets** | Queries `assets` table for current asset state, telemetry, device info. Reads `asset_telemetry` for recent positions. Snapshots asset state into `asset_snapshot_json`. |
| **Drivers** | Queries `drivers` table for driver profile, assignment, shift status. Queries recent driver events for risk assessment. Snapshots into `driver_snapshot_json`. |
| **Incidents** | Queries `incidents` table for open incidents matching the asset or driver. Creates `event_related_incident_links` for correlation. |
| **Integrations** | Uses provider API credentials (via `TenantIntegration`) to fetch deferred media from external providers. |
| **AI** | Produces `EventContextSnapshot` and `OperationalContextProfile` consumed by the AI module for event evaluation. `EventContextBuilt` triggers AI evaluation. |
| **RustFS** | Stores fetched media binaries via `ObjectStorage` contract. Storage path convention: `teams/{teamId}/events/{normalizedEventId}/media/{filename}`. |

## 12. Usage Metering

None directly. Context enrichment is an internal pipeline step. Media storage in RustFS contributes to the `stored_video_gb` meter tracked by the daily storage aggregation job in the Tenancy module.

## 13. Technical Considerations

### Performance

- `event_context_snapshots` stores denormalized JSON blobs to avoid expensive joins at AI evaluation time. The trade-off is storage size vs. query speed — AI evaluation reads a single row, not 5+ joined tables.
- The `(team_id, event_occurred_at)` index on `event_context_snapshots` supports time-range queries for dashboards.
- Geofence matching should be cached per team: load all active geofences once and match in-memory rather than querying per event. Cache in Valkey with 5-minute TTL keyed by `team:{teamId}:geofences`.
- Recent history queries use the `(asset_id, occurred_at)` index on `normalized_events`.

### Geofence Geometry

- `geometry_json` stores GeoJSON format for interoperability:
  - **Zone**: `{"type": "Polygon", "coordinates": [[[lng, lat], ...]]}`
  - **Point**: `{"type": "Point", "coordinates": [lng, lat], "radius_meters": 500}`
  - **Route**: `{"type": "LineString", "coordinates": [[lng, lat], ...], "buffer_meters": 100}`
- Point-in-polygon uses the ray-casting algorithm implemented in PHP. For production-scale deployments with complex polygons, consider PostGIS extension.

### Signals Generation

The `signals_json` field contains boolean flags computed from the assembled context:

| Signal | Computed From |
|--------|---------------|
| `is_in_sensitive_geofence` | Geofence match with category `risk_zone` or `border` |
| `has_open_incident` | Non-empty `event_related_incident_links` |
| `same_type_recent_recurrence` | `recent_same_type_count > 0` in history snapshot |
| `driver_has_recent_risk_events` | Driver context shows recent safety/compliance events |
| `camera_unavailable` | Asset snapshot indicates camera offline or obstructed |
| `gps_signal_weak` | Telemetry snapshot shows low GPS accuracy or stale position |
| `outside_operating_hours` | Event occurred outside team-configured operating hours |
| `asset_recently_stopped` | Telemetry shows speed = 0 in recent window |
| `asset_in_motion` | Telemetry shows speed > 0 at event time |
| `driver_unresolved_previous_alert` | Driver has open alerts from previous events |
| `has_visual_evidence` | At least one media context with type `image` or `video` is `available` |
| `has_audio_evidence` | At least one media context with type `audio` is `available` |
| `video_pending` | Media context exists with `retrieval_status = requested` or `processing` |
| `media_delayed` | Media request age exceeds expected provider response time |
| `no_media_available` | No media contexts exist or all are `not_available` |
| `visual_confirmation_possible` | Camera is available and media can be requested even if not yet fetched |

### Idempotency

- `BuildEventContext` uses `updateOrCreate` keyed on `normalized_event_id` (unique constraint). Re-enriching an event safely overwrites the existing snapshot.
- The `context_version` column increments on re-enrichment, providing an audit trail of context revisions.

### Media Storage

- Media files are stored in RustFS via the `ObjectStorage` contract.
- Storage path convention: `teams/{teamId}/events/{normalizedEventId}/media/{mediaType}_{timestamp}.{ext}`
- Thumbnail generation is handled asynchronously after the main media is stored.
- Media URLs in `event_media_contexts` may be temporary signed URLs from the provider; `storage_path` is the permanent RustFS location.

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_context_builds_complete_snapshot_with_all_sources` | A normalized event with known asset, driver, location, and open incident produces a full `EventContextSnapshot` with all `*_snapshot_json` fields populated |
| `test_context_builds_partial_snapshot_when_driver_unavailable` | A normalized event with `driver_id = null` still produces a valid snapshot with `driver_snapshot_json = null` |
| `test_geofence_match_detects_inside_zone` | An event with coordinates inside a polygon geofence creates a `GeofenceMatch` with `match_type = inside` |
| `test_related_open_incidents_found_for_same_asset` | An event for an asset with an open incident creates an `EventRelatedIncidentLink` with `relation_type = same_asset_open_incident` |
| `test_recent_history_counts_events_in_window` | An asset with 5 events in the last 60 minutes produces a history snapshot with `recent_events_count = 5` |
| `test_deferred_media_request_created_when_media_not_available` | When no immediate media is available, `RequestDeferredEventMedia` creates an `EventMediaRequest` with status `pending` |
| `test_media_arrival_triggers_context_refresh` | When `FetchDeferredEventMediaJob` completes, the `EventContextSnapshot.media_snapshot_json` and `signals_json` are updated |
| `test_signals_flag_sensitive_geofence` | An event inside a `risk_zone` geofence produces `signals_json` with `is_in_sensitive_geofence = true` |
| `test_signals_flag_no_media_available` | An event with no media contexts produces `signals_json` with `no_media_available = true` |
| `test_operational_context_profile_calculates_risk_level` | A high-severity event with recent recurrence and open incidents produces `risk_level = critical` |
| `test_context_enrichment_is_idempotent` | Calling `BuildEventContext` twice for the same normalized event results in exactly one `EventContextSnapshot` row |
| `test_geofence_point_match_within_radius` | An event near a point geofence (within configured radius) creates a match with `distance_meters` populated |
| `test_event_context_built_domain_event_dispatched` | `EventContextBuilt` fires after successful enrichment |
| `test_context_snapshot_scoped_to_team` | Viewing context for an event requires team membership; other teams cannot access it |
| `test_fetch_deferred_media_retries_on_provider_processing` | `FetchDeferredEventMediaJob` re-dispatches when the provider indicates media is still processing |
