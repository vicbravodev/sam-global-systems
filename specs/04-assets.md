# Assets (COMPLETADO)

## 1. Purpose

Represent and manage monitored assets (vehicles, cameras, GPS devices, sensors) as the internal source of truth, synced exclusively from external integration providers. Assets are never created manually — they originate from integration sync jobs and are enriched with telemetry and location data over time.

## 2. Responsibilities

- Maintain a catalog of asset types and their capabilities.
- Store and manage assets discovered from integration sync.
- Track the current and historical location of assets via location snapshots.
- Record telemetry data (speed, fuel, temperature, ignition, etc.) for each asset.
- Manage device attachment/detachment to assets.
- Maintain a cross-reference table linking assets to their external IDs across multiple providers.
- Derive asset status from recent events and telemetry.
- Broadcast asset status and location changes in real time.
- Provide daily asset counts for usage metering.

## 3. Inputs / Outputs

### Inputs

| Source | Data |
|--------|------|
| `App\Domains\Integrations` | Asset data from `SyncIntegrationJob` and webhook processing |
| `App\Domains\Ingestion` | Telemetry and location data from normalized events |

### Outputs

| Target | Data |
|--------|------|
| `App\Domains\Context` | Asset context for event enrichment (location, type, status) |
| `App\Domains\Drivers` | Asset reference for driver-asset assignments |
| `App\Domains\Incidents` | Asset details for incident context |
| `App\Domains\Tenancy` | Usage meter events (`monitored_assets`, `active_cameras`) |
| Frontend (Soketi) | `AssetStatusChangedBroadcast` and `AssetLocationUpdatedBroadcast` on `private-accounts.{teamId}` |

## 4. Entities

### 4.1 `asset_types`

Catalog of asset categories — seeded, not tenant-scoped.

```php
Schema::create('asset_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->string('category'); // enum: vehicle, trailer, camera, gps_device, sensor
    $table->json('capabilities_json')->nullable();
    $table->timestamps();
});
```

**Enum `AssetCategory`**: `Vehicle`, `Trailer`, `Camera`, `GpsDevice`, `Sensor`

### 4.2 `assets`

The core asset record, always associated with a tenant.

```php
Schema::create('assets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('asset_type_id')->constrained('asset_types');
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->foreignId('source_integration_id')->nullable()->constrained('tenant_integrations')->nullOnDelete();
    $table->string('external_primary_id')->nullable();
    $table->string('name');
    $table->string('code')->nullable();
    $table->string('status')->default('active'); // enum: active, inactive, offline, alert, critical, maintenance
    $table->json('metadata_json')->nullable();
    $table->timestamp('first_seen_at')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['team_id', 'status']);
    $table->index(['team_id', 'asset_type_id']);
});
```

**Enum `AssetStatus`**: `Active`, `Inactive`, `Offline`, `Alert`, `Critical`, `Maintenance`

### 4.3 `asset_devices`

Devices physically attached to an asset.

```php
Schema::create('asset_devices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
    $table->string('device_type');
    $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
    $table->string('external_device_id')->nullable();
    $table->string('status')->default('active'); // enum: active, inactive, detached
    $table->timestamp('attached_at');
    $table->timestamp('detached_at')->nullable();
    $table->json('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `DeviceStatus`**: `Active`, `Inactive`, `Detached`

### 4.4 `asset_location_snapshots`

Point-in-time GPS location records.

```php
Schema::create('asset_location_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
    $table->decimal('latitude', 10, 7);
    $table->decimal('longitude', 10, 7);
    $table->string('formatted_location')->nullable();
    $table->decimal('speed', 6, 2)->nullable();
    $table->smallInteger('heading')->nullable();
    $table->timestamp('recorded_at');
    $table->string('source'); // enum: provider, gps, manual
    $table->json('geocoding_metadata_json')->nullable();
    $table->timestamps();

    $table->index(['asset_id', 'recorded_at']);
});
```

**Enum `LocationSource`**: `Provider`, `Gps`, `Manual`

### 4.5 `asset_external_references`

Maps an asset to its external ID in each connected provider.

```php
Schema::create('asset_external_references', function (Blueprint $table) {
    $table->id();
    $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
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

### 4.6 `asset_telemetry_snapshots`

Time-series telemetry readings for an asset.

```php
Schema::create('asset_telemetry_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
    $table->string('telemetry_type'); // enum: speed, fuel, temperature, camera_status, battery, ignition, odometer
    $table->json('data_json');
    $table->timestamp('recorded_at');
    $table->string('source_event_id')->nullable();
    $table->timestamps();

    $table->index(['asset_id', 'recorded_at']);
});
```

**Enum `TelemetryType`**: `Speed`, `Fuel`, `Temperature`, `CameraStatus`, `Battery`, `Ignition`, `Odometer`

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `SyncAssetFromIntegration` | Upsert an asset from integration sync data. Resolves by external reference (provider + external_id), creates new asset if not found, updates existing if found. Dispatches `AssetDiscovered` for new assets. |
| `UpdateAssetStatus` | Transition an asset's status based on events or telemetry. Dispatches `AssetStatusChanged` and broadcasts to frontend. |
| `AttachDeviceToAsset` | Associate a device with an asset, detaching it from any previous asset. |
| `UpdateAssetLocationSnapshot` | Record a new location for an asset. Dispatches `AssetLocationUpdated` and broadcasts to frontend. |
| `ResolveAssetFromExternalId` | Look up an internal asset by provider + external ID. Returns `null` if not found. Used by Ingestion and Context domains. |

## 6. Jobs

### `SyncAssetsFromProviderJob`

- **Queue**: `sync`
- **Retry**: 3 attempts
- **Behaviour**: Fetches asset data from a provider's API for a given `tenant_integration_id`, calls `SyncAssetFromIntegration` for each asset discovered.

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `AssetDiscovered` | New asset created from integration sync | `teamId`, `assetId`, `assetTypeCode`, `providerCode`, `externalId` |
| `AssetStatusChanged` | `UpdateAssetStatus` transitions status | `teamId`, `assetId`, `previousStatus`, `newStatus` |
| `AssetLocationUpdated` | New location snapshot recorded | `teamId`, `assetId`, `latitude`, `longitude`, `recordedAt` |

## 8. Broadcasting Events

### `AssetStatusChangedBroadcast`

```php
namespace App\Domains\Assets\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AssetStatusChangedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly string $name,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'asset.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'asset_id' => $this->assetId,
            'name' => $this->name,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
```

### `AssetLocationUpdatedBroadcast`

```php
namespace App\Domains\Assets\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AssetLocationUpdatedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $recordedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'asset.location_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'asset_id' => $this->assetId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
```

## 9. APIs / Endpoints

Assets are read-only from the API perspective — they cannot be created or deleted manually.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/api/{current_team}/assets` | `AssetController@index` | List assets (filterable by status, type, search) |
| `GET` | `/api/{current_team}/assets/{asset}` | `AssetController@show` | Get asset detail with devices and latest location |
| `GET` | `/api/{current_team}/assets/{asset}/location-history` | `AssetController@locationHistory` | Paginated location snapshots for an asset |
| `GET` | `/api/{current_team}/assets/{asset}/telemetry` | `AssetController@telemetry` | Recent telemetry snapshots (filterable by type) |

All endpoints are protected by `EnsureTeamMembership` middleware. No `POST`, `PUT`, or `DELETE` — assets are managed exclusively through integration sync.

## 10. Business Rules

1. **No manual creation** — Assets CANNOT be created via API or UI. They originate only from integration sync jobs.
2. **Tenant isolation** — Every asset has a `team_id`. The `BelongsToTenant` trait enforces scoping.
3. **External reference uniqueness** — The combination of `(provider_id, external_id)` in `asset_external_references` is unique. Sync uses this to resolve existing assets.
4. **Soft delete only** — Assets are never hard-deleted. `softDeletes()` preserves historical data and audit trails.
5. **Status derivation** — Asset status is derived from recent events (telemetry, connectivity). `UpdateAssetStatus` is the single authority for status transitions.
6. **Provider-agnostic internal model** — Regardless of the source provider, all assets are normalized into the same internal schema.
7. **Device attachment exclusivity** — A device can only be attached to one asset at a time. Attaching to a new asset detaches from the previous one.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Tenancy** | `team_id` FK. Uses `BelongsToTenant` trait. Reports `monitored_assets` and `active_cameras` via `RecordUsageEvent`. |
| **Integrations** | `SyncAssetFromIntegration` is called by `SyncIntegrationJob`. `provider_id` and `source_integration_id` FKs link back to the integration that discovered the asset. |
| **Drivers** | `driver_assignments.asset_id` FK references `assets.id`. Drivers domain queries assets for assignment resolution. |
| **Ingestion** | Raw events reference assets via external IDs. Ingestion calls `ResolveAssetFromExternalId` to link events to internal assets. |
| **Context** | Context domain reads asset data (location, type, status) to enrich normalized events. |
| **Incidents** | Incidents reference the asset involved. Asset details provide context for incident analysis. |

## 12. Usage Metering

### `monitored_assets` meter

- **Trigger**: Daily scheduled job.
- **Logic**: Count distinct active (non-soft-deleted, status != `inactive`) assets per team.
- **Implementation**: A scheduled command calls `RecordUsageEvent` with `meterCode: 'monitored_assets'`, `quantity: count`, `eventKey: "monitored_assets:{teamId}:{date}"`.

### `active_cameras` meter

- **Trigger**: Daily scheduled job.
- **Logic**: Count distinct active assets where `asset_types.category = 'camera'` per team.
- **Implementation**: Same pattern, `meterCode: 'active_cameras'`, `eventKey: "active_cameras:{teamId}:{date}"`.

## 13. Technical Considerations

- **High-frequency location data** — `asset_location_snapshots` will grow rapidly. Consider partitioning by `recorded_at` or archiving old snapshots to cold storage.
- **Telemetry volume** — Similar concerns for `asset_telemetry_snapshots`. Index on `(asset_id, recorded_at)` is critical for query performance.
- **Geocoding** — Reverse geocoding (lat/lng → address) should be done asynchronously to avoid blocking sync. Store results in `formatted_location` and `geocoding_metadata_json`.
- **Upsert strategy** — `SyncAssetFromIntegration` should use `updateOrCreate` keyed on `(provider_id, external_id)` via the external references table for idempotent sync.
- **N+1 prevention** — API endpoints should eager-load `assetType`, `devices`, and latest location snapshot.
- **Pagination** — Location history and telemetry endpoints must use cursor-based pagination for time-series data.
- **Real-time location throttling** — Broadcasting every location update could overwhelm the WebSocket. Consider throttling broadcasts to at most once per asset per N seconds.

## 14. Test Scenarios

### Asset Sync

- `test_it_creates_asset_from_integration_sync`
- `test_it_updates_existing_asset_on_duplicate_external_id`
- `test_it_dispatches_asset_discovered_event_for_new_asset`
- `test_it_resolves_asset_from_external_id_and_provider`
- `test_it_returns_null_for_unknown_external_id`

### Asset Status

- `test_it_updates_asset_status_and_dispatches_event`
- `test_it_broadcasts_asset_status_changed`
- `test_it_does_not_dispatch_event_when_status_unchanged`

### Location

- `test_it_records_location_snapshot_for_asset`
- `test_it_dispatches_asset_location_updated_event`
- `test_it_broadcasts_asset_location_updated`
- `test_it_returns_paginated_location_history`

### Devices

- `test_it_attaches_device_to_asset`
- `test_it_detaches_device_from_previous_asset_on_reattach`

### Tenant Isolation

- `test_it_scopes_assets_to_current_team`
- `test_it_cannot_access_another_teams_assets`

### Soft Delete

- `test_it_soft_deletes_asset`
- `test_it_excludes_soft_deleted_assets_from_queries`

### Usage Metering

- `test_it_records_monitored_assets_meter_daily`
- `test_it_records_active_cameras_meter_daily`
- `test_it_excludes_inactive_assets_from_meter_count`

### API

- `test_it_lists_assets_with_filters`
- `test_it_shows_asset_detail_with_devices_and_location`
- `test_it_rejects_manual_asset_creation`
- `test_it_returns_telemetry_snapshots_filtered_by_type`
