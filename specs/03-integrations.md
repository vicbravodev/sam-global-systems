# Integrations (COMPLETADO)

## 1. Purpose

Connect SAM with external telematics, video, and IoT platforms (Samsara, Motive, Geotab, Lytx, etc.), managing a provider catalog, tenant connections, encrypted credentials, webhook ingestion, and periodic data sync. This domain is the single entry point for all external data sources.

## 2. Responsibilities

- Maintain a catalog of supported integration providers and their capabilities.
- Allow tenants to connect, configure, test, and disconnect integrations.
- Store and rotate encrypted credentials per tenant integration.
- Generate and manage webhook endpoints for receiving provider callbacks.
- Orchestrate full and incremental sync jobs against external APIs.
- Validate webhook signatures before processing payloads.
- Persist webhook events immediately and process them asynchronously.
- Broadcast integration status changes to the frontend in real time.

## 3. Inputs / Outputs

### Inputs

| Source | Data |
|--------|------|
| Admin seeder / migration | Provider catalog (`integration_providers`) |
| Tenant UI / API | Connection config, credentials, enable/disable actions |
| External provider | Webhook HTTP POST with signed payload |
| Scheduler | Periodic sync triggers |

### Outputs

| Target | Data |
|--------|------|
| `App\Domains\Ingestion` | Raw event payloads forwarded from webhook processing and sync jobs |
| `App\Domains\Assets` | Asset discovery data during sync |
| `App\Domains\Drivers` | Driver discovery data during sync |
| Frontend (Soketi) | `IntegrationStatusChanged` broadcast on `private-accounts.{teamId}` |

## 4. Entities

### 4.1 `integration_providers`

Provider catalog — seeded, not tenant-scoped.

```php
Schema::create('integration_providers', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->string('type'); // enum: telematics, video, iot, api
    $table->string('status')->default('active'); // enum: active, inactive, deprecated
    $table->json('config_schema_json')->nullable();
    $table->json('capabilities_json')->nullable();
    $table->timestamps();
});
```

**Enum `IntegrationProviderType`**: `Telematics`, `Video`, `Iot`, `Api`

**Enum `IntegrationProviderStatus`**: `Active`, `Inactive`, `Deprecated`

### 4.2 `tenant_integrations`

A tenant's connection to a specific provider.

```php
Schema::create('tenant_integrations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
    $table->string('name');
    $table->string('status')->default('pending'); // enum: active, inactive, error, pending
    $table->string('auth_type'); // enum: api_key, oauth2, basic_auth, token
    $table->text('credentials_encrypted');
    $table->json('config_json')->nullable();
    $table->timestamp('last_sync_at')->nullable();
    $table->timestamp('last_error_at')->nullable();
    $table->text('last_error_message')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'status']);
});
```

The `credentials_encrypted` column uses Laravel's `encrypted` cast to ensure credentials are encrypted at rest.

**Enum `TenantIntegrationStatus`**: `Active`, `Inactive`, `Error`, `Pending`

**Enum `AuthType`**: `ApiKey`, `Oauth2`, `BasicAuth`, `Token`

### 4.3 `integration_credentials`

Individual credential key-value pairs with rotation tracking.

```php
Schema::create('integration_credentials', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_integration_id')->constrained('tenant_integrations')->cascadeOnDelete();
    $table->string('key');
    $table->text('value_encrypted');
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('rotated_at')->nullable();
    $table->timestamps();
});
```

### 4.4 `integration_sync_jobs`

Tracks each sync execution for observability.

```php
Schema::create('integration_sync_jobs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_integration_id')->constrained('tenant_integrations')->cascadeOnDelete();
    $table->string('type'); // enum: full, incremental, realtime
    $table->string('status')->default('pending'); // enum: pending, running, completed, failed
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->unsignedInteger('records_processed')->default(0);
    $table->text('error_message')->nullable();
    $table->timestamps();
});
```

**Enum `SyncType`**: `Full`, `Incremental`, `Realtime`

**Enum `SyncStatus`**: `Pending`, `Running`, `Completed`, `Failed`

### 4.5 `webhook_endpoints`

Auto-generated inbound webhook URLs per integration.

```php
Schema::create('webhook_endpoints', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_integration_id')->constrained('tenant_integrations')->cascadeOnDelete();
    $table->string('url')->unique(); // generated UUID-based path
    $table->string('secret');
    $table->string('status')->default('active'); // enum: active, inactive
    $table->timestamp('last_received_at')->nullable();
    $table->timestamps();
});
```

### 4.6 `webhook_events`

Immutable log of every received webhook payload.

```php
Schema::create('webhook_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->string('event_type');
    $table->json('payload_json');
    $table->timestamp('received_at');
    $table->timestamp('processed_at')->nullable();
    $table->string('status')->default('received'); // enum: received, processing, processed, failed, invalid_signature
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'received_at']);
});
```

**Enum `WebhookEventStatus`**: `Received`, `Processing`, `Processed`, `Failed`, `InvalidSignature`

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `SyncIntegration` | Execute a full or incremental sync against the provider API, create an `integration_sync_jobs` record, forward discovered data to Ingestion/Assets/Drivers. |
| `HandleWebhook` | Persist the raw webhook event, dispatch `ProcessWebhookEventJob`. |
| `ValidateWebhookSignature` | Verify HMAC or provider-specific signature against the endpoint secret. Returns boolean. |
| `RotateCredentials` | Generate new credentials, update `integration_credentials`, mark `rotated_at`. |
| `TestIntegrationConnection` | Perform a lightweight API call to the provider to verify credentials are valid. Returns success/failure with diagnostics. |

## 6. Jobs

### `SyncIntegrationJob`

- **Queue**: `sync`
- **Retry**: 3 attempts
- **Backoff**: `[60, 300, 900]` (exponential)
- **Behaviour**: Creates an `integration_sync_jobs` record, calls `SyncIntegration`, updates the record on completion or failure. Dispatches `IntegrationSyncCompleted` event.

### `ProcessWebhookEventJob`

- **Queue**: `ingestion`
- **Retry**: 3 attempts
- **Behaviour**: Loads the `webhook_events` row, validates signature via `ValidateWebhookSignature`, parses payload, forwards to the Ingestion domain via `StoreRawEvent`. Marks the row as `processed` or `failed`.

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `IntegrationConnected` | Tenant integration status changes to `active` | `teamId`, `integrationId`, `providerCode` |
| `IntegrationDisconnected` | Tenant integration is deleted or set to `inactive` | `teamId`, `integrationId`, `providerCode` |
| `IntegrationSyncCompleted` | `SyncIntegrationJob` finishes | `teamId`, `integrationId`, `syncJobId`, `recordsProcessed` |
| `WebhookReceived` | `HandleWebhook` persists a new event | `teamId`, `webhookEventId`, `eventType` |

## 8. Broadcasting Events

### `IntegrationStatusChanged`

Broadcast on `private-accounts.{teamId}` whenever a tenant integration's status changes.

```php
namespace App\Domains\Integrations\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class IntegrationStatusChanged implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $integrationId,
        public readonly string $providerCode,
        public readonly string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'integration.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'integration_id' => $this->integrationId,
            'provider_code' => $this->providerCode,
            'status' => $this->status,
        ];
    }
}
```

## 9. APIs / Endpoints

All tenant-scoped endpoints are prefixed with `/api/{current_team}` and protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `POST` | `/api/{current_team}/integrations` | `IntegrationController@store` | Create a new tenant integration |
| `GET` | `/api/{current_team}/integrations` | `IntegrationController@index` | List tenant integrations |
| `PUT` | `/api/{current_team}/integrations/{integration}` | `IntegrationController@update` | Update integration config/credentials |
| `DELETE` | `/api/{current_team}/integrations/{integration}` | `IntegrationController@destroy` | Disconnect an integration |
| `POST` | `/api/{current_team}/integrations/{integration}/test` | `IntegrationController@test` | Test the integration connection |
| `POST` | `/webhooks/{endpoint_url}` | `WebhookController@handle` | Receive webhook (public, no auth — validated by signature) |

The webhook endpoint is **public** — it does not require authentication. Validation is performed via the `ValidateWebhookSignature` service using the endpoint's stored secret.

## 10. Business Rules

1. **Encryption at rest** — Credentials stored in `credentials_encrypted` and `value_encrypted` MUST use Laravel's `encrypted` cast. Raw credentials must never appear in logs, exceptions, or API responses.
2. **Webhook signature validation** — Every incoming webhook MUST have its signature validated before payload processing. Invalid signatures result in a `WebhookEventStatus::InvalidSignature` record and no further processing.
3. **Persist first, process later** — Webhook payloads are written to `webhook_events` immediately upon receipt. Processing happens asynchronously via `ProcessWebhookEventJob`.
4. **Multiple integrations per tenant** — A tenant can connect multiple providers, or multiple instances of the same provider.
5. **Idempotent sync** — Sync jobs must handle duplicate data gracefully (upsert by external ID).
6. **Credential rotation** — Rotating credentials MUST NOT cause downtime. The new credential is written before the old one is invalidated.
7. **Provider deprecation** — A deprecated provider prevents new connections but does not disable existing ones.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Tenancy** | `team_id` FK on `tenant_integrations` and `webhook_events`. Uses `BelongsToTenant` trait. |
| **Access** | Policies check team membership and permissions before allowing integration CRUD. |
| **Ingestion** | `ProcessWebhookEventJob` and `SyncIntegrationJob` forward raw payloads to `StoreRawEvent` in the Ingestion domain. |
| **Assets** | Sync jobs call `SyncAssetFromIntegration` when asset data is discovered. |
| **Drivers** | Sync jobs call `SyncDriverFromIntegration` when driver data is discovered. |

## 12. Usage Metering

None directly. Integration activity is metered downstream:

- Webhook payloads become raw events, metered in Ingestion (`api_requests`).
- Synced assets are counted in Assets (`monitored_assets`, `active_cameras`).

## 13. Technical Considerations

- **Rate limiting on webhook endpoint** — Apply a per-endpoint rate limit (e.g., 100 req/s) to prevent abuse.
- **Webhook replay protection** — Store a hash of the payload + timestamp to detect and reject replayed events.
- **Sync job timeout** — Full syncs can be long-running. Set a per-job timeout (e.g., 30 minutes) and break large syncs into paginated batches.
- **Provider SDK abstraction** — Each provider should implement a common `ProviderAdapter` interface so the sync service is provider-agnostic.
- **Connection pooling** — HTTP clients for external APIs should use connection pooling via Laravel's HTTP client with retry middleware.
- **Sensitive data in logs** — Ensure the `credentials_encrypted` and `value_encrypted` fields are added to `$hidden` on models and excluded from any logging context.
- **Dead-letter handling** — Webhook events that fail processing after all retries should be flagged for manual review.

## 14. Test Scenarios

### Integration CRUD

- `test_it_creates_tenant_integration_with_encrypted_credentials`
- `test_it_lists_only_integrations_belonging_to_current_team`
- `test_it_updates_integration_config_and_credentials`
- `test_it_deletes_integration_and_marks_inactive`
- `test_it_prevents_creating_integration_for_deprecated_provider`

### Connection Test

- `test_it_returns_success_when_provider_credentials_are_valid`
- `test_it_returns_failure_when_provider_credentials_are_invalid`

### Webhook Processing

- `test_it_persists_webhook_event_before_processing`
- `test_it_rejects_webhook_with_invalid_signature`
- `test_it_marks_event_as_invalid_signature_on_failure`
- `test_it_dispatches_process_webhook_event_job_on_receipt`
- `test_it_forwards_processed_webhook_to_ingestion`

### Sync

- `test_it_creates_sync_job_record_on_start`
- `test_it_completes_sync_and_records_processed_count`
- `test_it_marks_sync_as_failed_on_provider_error`
- `test_it_retries_sync_with_exponential_backoff`
- `test_it_handles_duplicate_data_idempotently`

### Credential Rotation

- `test_it_rotates_credentials_without_downtime`
- `test_it_updates_rotated_at_timestamp`

### Tenant Isolation

- `test_it_scopes_integrations_to_current_team`
- `test_it_cannot_access_another_teams_integration`

### Broadcasting

- `test_it_broadcasts_integration_status_changed_on_activation`
- `test_it_broadcasts_integration_status_changed_on_error`

## 15. Samsara Webhook Payload Examples

Real-world examples of Samsara webhook payloads received via `POST /api/webhooks/{endpoint_url}`. Both arrive as `eventType: "AlertIncident"` but carry different structures depending on the alert configuration.

### 15.1 Safety Event (e.g. Forward Collision Warning)

Triggered by the Samsara safety stream. The `_source` field is added by the stream configuration. `conditions` contains the safety event type.

```json
{
  "id": "54458224-658a-56a2-ba11-0c00eb28e527",
  "data": {
    "conditions": [
      {
        "description": "Advertencia de colisión frontal"
      }
    ],
    "happenedAtTime": "2026-04-12T14:41:56+00:00"
  },
  "driver": {
    "id": "51909928",
    "name": "MANUEL DE JESUS DE LA CRUZ GUTIERREZ"
  },
  "_source": "safety_stream",
  "eventId": "54458224-658a-56a2-ba11-0c00eb28e527",
  "vehicle": {
    "id": "281474991548953",
    "name": "T-381 JC 73AT7F"
  },
  "eventTime": "2026-04-12T14:41:56+00:00",
  "eventType": "AlertIncident",
  "vehicleId": "281474991548953"
}
```

**Key fields for normalization:**
- `_source: "safety_stream"` — identifies this as a safety camera/sensor event.
- `data.conditions[].description` — the safety event type in the tenant's locale (e.g. "Advertencia de colisión frontal" = forward collision warning).
- `driver` — embedded driver info (external `id` and `name`).
- `vehicle` — embedded vehicle info (external `id` and `name`).

### 15.2 Panic Button (Workflow Alert)

Triggered by a Samsara Workflow alert with a `triggerId` for the panic button. Contains richer metadata including tags, serial numbers, and an `incidentUrl` for the Samsara dashboard.

```json
{
  "data": {
    "conditions": [
      {
        "details": {
          "panicButton": {
            "driver": {
              "id": "51910056",
              "name": "VICTOR JIMENEZ GARCIA",
              "tags": [
                { "id": "4691864", "name": "CREDENCIALIZADO" },
                { "id": "4691922", "name": "LOCAL JC" },
                { "id": "4691924", "name": "REGIONAL JC" }
              ]
            },
            "vehicle": {
              "id": "281474994676286",
              "name": "T-881 JC 51BJ7M",
              "tags": [
                { "id": "5259276", "name": "FLOTA ERUBIEL" },
                { "id": "4691862", "name": "FORANEO JC" },
                { "id": "4724218", "name": "KRONH" }
              ],
              "serial": "GP6GM6TSXC",
              "externalIds": {
                "samsara.vin": "3WKYD40X9TF567097",
                "samsara.serial": "GP6GM6TSXC"
              }
            }
          }
        },
        "triggerId": 1034,
        "description": "Panic Button"
      }
    ],
    "isResolved": true,
    "incidentUrl": "https://cloud.samsara.com/o/4006685/fleet/workflows/incidents/a0818d04-f303-4361-b1c9-5aac8befcc13/1/281474994676286/1775599909443",
    "updatedAtTime": "2026-04-07T22:12:04Z",
    "happenedAtTime": "2026-04-07T22:11:49Z",
    "resolvedAtTime": "2026-04-07T22:11:54Z",
    "configurationId": "a0818d04-f303-4361-b1c9-5aac8befcc13"
  },
  "orgId": 4006685,
  "eventId": "b349a25c-c445-4a17-8c5b-12dd77f81bc4",
  "eventTime": "2026-04-07T22:11:49.443Z",
  "eventType": "AlertIncident",
  "webhookId": "541115021054798"
}
```

**Key fields for normalization:**
- `data.conditions[].description: "Panic Button"` — identifies this as a panic button event.
- `data.conditions[].details.panicButton` — nested object with full driver and vehicle data including tags.
- `data.isResolved` / `data.resolvedAtTime` — Samsara auto-resolves panic buttons after ~5 seconds; SAM should treat these as open incidents regardless.
- `data.incidentUrl` — direct link to the Samsara dashboard incident view.
- `orgId` / `webhookId` — Samsara org and webhook configuration identifiers.
- `driver.tags` / `vehicle.tags` — Samsara tag groups useful for fleet segmentation mapping.
- `vehicle.externalIds` — VIN and serial for asset matching.

### 15.3 Discriminating Between Event Subtypes

Both payloads share `eventType: "AlertIncident"`. The Normalization domain must use these heuristics:

| Heuristic | Safety Event | Panic Button |
|-----------|-------------|--------------|
| `_source` field | `"safety_stream"` | absent |
| `data.conditions[].details.panicButton` | absent | present |
| `data.conditions[].triggerId` | absent | present (numeric) |
| `data.conditions[].description` | Safety description (locale-dependent) | `"Panic Button"` |
| `data.isResolved` / `resolvedAtTime` | absent | present |
| `data.incidentUrl` | absent | present |
