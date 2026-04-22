# Ingestion

## 1. Purpose

Receive raw events from external providers via webhooks or API polling and persist them reliably before any transformation. This is SAM's trusted front door — persist first, process later. The Ingestion module guarantees that no inbound event is ever lost, regardless of downstream processing failures.

## 2. Responsibilities

- Accept inbound webhooks from external providers and persist the raw payload immediately
- Poll external provider APIs on a schedule to pull events that are not push-delivered
- Validate webhook signatures without blocking persistence (invalid signatures are flagged, not dropped)
- Deduplicate events using configurable deduplication keys
- Track processing attempts and status for every raw event
- Store event attachments (snapshots, clips, documents) in RustFS via `ObjectStorage` contract
- Dispatch persisted events to the Normalization module for downstream processing
- Provide an admin/debug API for inspecting raw events

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| External providers | Webhook HTTP POST payloads | `POST /webhooks/{endpointUrl}` |
| Integrations module | Polling trigger with provider credentials | `PollExternalProviderJob` |
| Integrations module | Batch import payloads | Direct service call |
| External providers | Request headers, signatures | HTTP headers |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Normalization module | `RawEvent` model (with status `pending_processing`) | `ProcessRawEventJob` → dispatches to normalization queue |
| RustFS | Attachment binaries (snapshots, clips, documents) | `ObjectStorage` contract |
| Admin UI | Raw event list for debugging | `GET /api/{team}/events/raw` |

## 4. Entities

### 4.1 Event Sources (`event_sources`)

Defines where events originate from — a webhook endpoint, a polling configuration, or a batch import channel.

```php
Schema::create('event_sources', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->foreignId('tenant_integration_id')->nullable()->constrained('tenant_integrations')->nullOnDelete();
    $table->string('source_type'); // webhook, polling, batch_import, api_pull, message_queue
    $table->string('source_name');
    $table->string('status')->default('active'); // active, inactive
    $table->jsonb('config_json')->nullable();
    $table->timestamps();

    $table->index('team_id');
});
```

**Enum `EventSourceType`**: `Webhook`, `Polling`, `BatchImport`, `ApiPull`, `MessageQueue`

**Enum `EventSourceStatus`**: `Active`, `Inactive`

### 4.2 Raw Events (`raw_events`)

The immutable record of every inbound event exactly as received. This is the single source of truth for what the system received.

```php
Schema::create('raw_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('event_source_id')->constrained('event_sources')->cascadeOnDelete();
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->string('external_event_id')->nullable();
    $table->string('event_type_raw')->nullable();
    $table->jsonb('payload_json');
    $table->jsonb('headers_json')->nullable();
    $table->timestamp('received_at');
    $table->timestamp('occurred_at')->nullable();
    $table->string('deduplication_key')->nullable();
    $table->string('status')->default('received'); // received, duplicate_detected, pending_processing, processing, processed, failed, discarded, invalid_signature, malformed
    $table->string('checksum')->nullable();
    $table->unsignedTinyInteger('processing_attempts')->default(0);
    $table->timestamp('last_processing_attempt_at')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'received_at']);
    $table->index(['team_id', 'status']);
    $table->index('deduplication_key');
});
```

**Enum `RawEventStatus`**: `Received`, `DuplicateDetected`, `PendingProcessing`, `Processing`, `Processed`, `Failed`, `Discarded`, `InvalidSignature`, `Malformed`

### 4.3 Event Receipts (`event_receipts`)

Metadata about how each raw event was received — transport-level details for audit and debugging.

```php
Schema::create('event_receipts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('raw_event_id')->constrained('raw_events')->cascadeOnDelete();
    $table->string('received_via'); // webhook, polling, batch_import, api_pull
    $table->string('request_id')->nullable();
    $table->string('source_ip')->nullable();
    $table->string('user_agent')->nullable();
    $table->unsignedSmallInteger('http_status_returned')->nullable();
    $table->boolean('signature_valid')->nullable();
    $table->timestamp('received_at');
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
});
```

### 4.4 Event Deduplication Keys (`event_deduplication_keys`)

Tracks deduplication keys with expiration to prevent unbounded growth while guaranteeing idempotency within a window.

```php
Schema::create('event_deduplication_keys', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('event_source_id')->constrained('event_sources')->cascadeOnDelete();
    $table->string('deduplication_key');
    $table->foreignId('raw_event_id')->constrained('raw_events')->cascadeOnDelete();
    $table->timestamp('first_seen_at');
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();

    $table->unique(['event_source_id', 'deduplication_key']);
});
```

### 4.5 Raw Event Attachments (`raw_event_attachments`)

Binary media associated with a raw event, stored in RustFS via the `ObjectStorage` contract.

```php
Schema::create('raw_event_attachments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('raw_event_id')->constrained('raw_events')->cascadeOnDelete();
    $table->string('attachment_type'); // snapshot, image, clip, document
    $table->string('storage_path');
    $table->string('mime_type')->nullable();
    $table->unsignedBigInteger('size_bytes')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `AttachmentType`**: `Snapshot`, `Image`, `Clip`, `Document`

## 5. Services / Actions

### 5.1 `StoreRawEvent`

**Path**: `app/Domains/Ingestion/Actions/StoreRawEvent.php`

```php
public function execute(
    array $payload,
    string $sourceType,
    ?int $teamId,
    ?int $providerId,
    ?string $externalEventId,
    ?array $headers = null,
): RawEvent
```

- Resolve or create `EventSource` for the given `$sourceType` and `$providerId`
- Persist `payload_json` exactly as received — no transformation
- Set `received_at` to `now()`
- Compute `checksum` from `payload_json` (SHA-256)
- Generate `deduplication_key` from `$externalEventId` or checksum if not provided
- Create `EventReceipt` with transport metadata
- Return the persisted `RawEvent` model
- Dispatch `RawEventReceived` domain event

### 5.2 `DetectDuplicateEvent`

**Path**: `app/Domains/Ingestion/Actions/DetectDuplicateEvent.php`

```php
public function execute(RawEvent $rawEvent): bool
```

- Generate deduplication key from `$rawEvent->deduplication_key` or compute from checksum
- Query `event_deduplication_keys` for matching `(event_source_id, deduplication_key)` excluding expired entries
- If match found: update `$rawEvent->status` to `duplicate_detected`, dispatch `RawEventDuplicated`, return `true`
- If no match: insert new `EventDeduplicationKey` record, return `false`

### 5.3 `ValidateIncomingSignature`

**Path**: `app/Domains/Ingestion/Actions/ValidateIncomingSignature.php`

```php
public function execute(
    string $payload,
    string $signature,
    string $secret,
    string $algorithm = 'sha256',
): bool
```

- Compute HMAC of `$payload` using `$secret` and `$algorithm`
- Compare computed HMAC with `$signature` using timing-safe comparison (`hash_equals`)
- Return `true` if valid, `false` otherwise

### 5.4 `QueueRawEventForProcessing`

**Path**: `app/Domains/Ingestion/Actions/QueueRawEventForProcessing.php`

```php
public function execute(RawEvent $rawEvent): void
```

- Update `$rawEvent->status` to `pending_processing`
- Dispatch `ProcessRawEventJob` to the `ingestion` queue with `$rawEvent->id`

## 6. Jobs

### 6.1 `ProcessRawEventJob`

- **Queue**: `ingestion`
- **Retry**: 3
- **Backoff**: `[10, 60, 300]` (exponential)
- **Logic**:
  1. Load `RawEvent` by ID
  2. Run `DetectDuplicateEvent` — if duplicate, stop processing
  3. Update status to `processing`, increment `processing_attempts`
  4. Dispatch to the normalization queue (triggers `NormalizeEventJob` in the Normalization module)
  5. Update status to `processed` on success
  6. On failure: update status to `failed`, set `last_processing_attempt_at`, dispatch `RawEventFailed`

### 6.2 `PollExternalProviderJob`

- **Queue**: `sync`
- **Retry**: 2
- **Logic**:
  1. Load provider credentials and polling configuration from `EventSource`
  2. Call provider API to fetch events since last poll timestamp
  3. For each event returned: call `StoreRawEvent` with `sourceType = 'polling'`
  4. Update the polling cursor/timestamp in `event_sources.config_json`
  5. Dispatch `QueueRawEventForProcessing` for each new `RawEvent`

## 7. Domain Events

| Event | Payload | Dispatched When |
|-------|---------|-----------------|
| `RawEventReceived` | `RawEvent $rawEvent` | A new raw event is persisted |
| `RawEventDuplicated` | `RawEvent $rawEvent, string $deduplicationKey` | A duplicate event is detected |
| `RawEventFailed` | `RawEvent $rawEvent, string $reason` | Processing fails after all retries |

## 8. Broadcasting Events

None. The Ingestion module does not broadcast to frontend clients. Downstream modules (Normalization, Incidents) handle user-facing broadcasts.

## 9. APIs / Endpoints

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| POST | `/webhooks/{endpointUrl}` | `WebhookController@receive` | Public webhook receiver — no CSRF, no auth |
| GET | `/{current_team}/events/raw` | `RawEventController@index` | List raw events (admin/debug, paginated) |

### Webhook Endpoint Details

- **CSRF**: Excluded in `bootstrap/app.php` via `validateCsrfTokens(except: ['webhooks/*'])`
- **Auth**: None — public endpoint. Authentication is via signature validation per provider.
- **Response**: Always returns `200 OK` with `{"received": true}` immediately after persisting
- **Rate Limiting**: Apply provider-specific rate limits via middleware

### Admin Endpoint Details

- **Auth**: Requires authenticated user with team membership
- **Scoping**: Results filtered by `team_id` via `BelongsToTenant` trait
- **Pagination**: Standard Laravel pagination, default 25 per page
- **Filters**: `status`, `event_source_id`, `received_at` range

## 10. Business Rules

1. **NEVER transform before persisting** — `payload_json` is saved exactly as received, byte-for-byte. Transformation happens exclusively in the Normalization module.
2. **Webhook endpoints return 200 BEFORE processing** — receipt is decoupled from processing. The provider must never see a timeout or error due to downstream failures.
3. **Deduplication is mandatory** — the same event from the same source must not process twice. Deduplication keys have configurable TTLs to bound storage.
4. **Failed downstream processing does NOT affect the persisted raw event** — a `RawEvent` with status `received` or `processed` is never deleted or modified due to normalization failures.
5. **Raw events with invalid signatures are persisted with status `invalid_signature` but not processed** — this preserves evidence for security auditing while preventing untrusted data from entering the pipeline.
6. **Attachments (media) are stored in RustFS via the `ObjectStorage` contract** — binary data is never stored in PostgreSQL. The `storage_path` column references the RustFS object key.
7. **Processing attempts are tracked for retry visibility** — `processing_attempts` and `last_processing_attempt_at` provide operational insight into retry behavior.

## 11. Integration with Other Modules

| Module | Integration Point |
|--------|-------------------|
| **Integrations** | Provides webhook endpoint URLs and polling configurations. `TenantIntegration` determines which provider credentials to use for signature validation and polling. |
| **Normalization** | Receives `RawEvent` IDs via `ProcessRawEventJob` dispatch to the `normalization` queue. Normalization reads the persisted `RawEvent` to produce a `NormalizedEvent`. |
| **Assets** | Indirectly — raw events may contain asset identifiers resolved during normalization. |
| **Drivers** | Indirectly — raw events may contain driver identifiers resolved during normalization. |
| **Audit** | `RawEventReceived`, `RawEventDuplicated`, and `RawEventFailed` events are available for audit logging. |

## 12. Usage Metering

None directly. Raw event ingestion volume is not metered independently. Usage metering occurs at the AI evaluation step (in the AI module), where billable work is performed. The `api_requests` meter in the Integrations module may cover inbound webhook volume if configured.

## 13. Technical Considerations

### Performance

- `raw_events` will grow rapidly in high-volume deployments. Add time-based partitioning on `received_at` when the table exceeds 50M rows.
- The `(team_id, received_at)` and `(team_id, status)` indexes support the most common query patterns (admin listing, reprocessing queues).
- The `deduplication_key` index enables fast duplicate lookups without full-table scans.
- Webhook responses must complete in under 200ms — persist and return, nothing else.

### CSRF Exclusion

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'webhooks/*',
    ]);
})
```

### Signature Validation

Each provider has its own signing mechanism. The `ValidateIncomingSignature` action supports configurable algorithms. Provider-specific signature extraction (header name, encoding) is configured in the `EventSource.config_json`:

```json
{
    "signature_header": "X-Provider-Signature",
    "signature_algorithm": "sha256",
    "signature_secret_key": "webhook_secret"
}
```

### Idempotency

- `DetectDuplicateEvent` uses the `unique(event_source_id, deduplication_key)` constraint on `event_deduplication_keys`.
- Expired deduplication keys are cleaned up by a scheduled job to prevent unbounded table growth.
- `StoreRawEvent` does not use `insertOrIgnore` — it always persists, and deduplication is a separate step that flags (not prevents) duplicate records.

### Attachment Storage

- Attachments use the `ObjectStorage` contract bound to the `rustfs` disk.
- Storage path convention: `teams/{teamId}/raw-events/{rawEventId}/{filename}`
- Mime type detection uses PHP's `finfo` extension.

### Retry Strategy

- `ProcessRawEventJob` uses exponential backoff: 10s, 60s, 300s (5 min).
- After 3 failed attempts, the event is marked `failed` and requires manual reprocessing or automated sweep.
- `PollExternalProviderJob` retries twice with standard backoff — polling failures are transient (API rate limits, network issues).

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_webhook_persists_raw_event_and_returns_200` | POST to `/webhooks/{endpointUrl}` with valid payload persists a `RawEvent` with status `received` and returns HTTP 200 |
| `test_duplicate_event_detected_and_marked` | Submitting two events with the same deduplication key marks the second as `duplicate_detected` |
| `test_invalid_signature_persists_but_does_not_process` | A webhook with an invalid signature creates a `RawEvent` with status `invalid_signature` and does not dispatch `ProcessRawEventJob` |
| `test_raw_event_dispatches_to_normalization_queue` | `ProcessRawEventJob` for a valid, non-duplicate event dispatches to the `normalization` queue |
| `test_poll_job_creates_raw_events_from_provider` | `PollExternalProviderJob` creates one `RawEvent` per event returned by the provider API |
| `test_malformed_payload_is_persisted_with_malformed_status` | A webhook with unparseable body persists the raw body with status `malformed` |
| `test_event_receipt_records_transport_metadata` | After webhook receipt, an `EventReceipt` exists with correct `source_ip`, `user_agent`, and `received_via` |
| `test_attachment_stored_in_rustfs` | A raw event with binary attachment stores the file via `ObjectStorage` and records the `storage_path` |
| `test_processing_attempts_increment_on_retry` | Each retry of `ProcessRawEventJob` increments `processing_attempts` and updates `last_processing_attempt_at` |
| `test_deduplication_keys_expire_after_ttl` | Expired deduplication keys do not block new events with the same key |
| `test_raw_event_received_domain_event_dispatched` | `RawEventReceived` event fires when `StoreRawEvent` completes |
| `test_raw_event_scoped_to_team` | Admin listing at `/{current_team}/events/raw` only returns events for the authenticated team |
