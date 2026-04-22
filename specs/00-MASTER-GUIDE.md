# SAM Global Systems -- Master Implementation Guide

## 1. Project Context

- **Stack**: Laravel 13, Inertia.js v3, React 19, Tailwind CSS v4, PHP 8.5
- **Database**: PostgreSQL 18
- **Key-Value Store**: Valkey (sole KV store, no Redis). Wire-compatible with Redis protocol.
- **Object Storage**: RustFS (S3-compatible)
- **WebSocket Server**: Soketi (Pusher-compatible)
- **Mail (dev)**: Mailpit
- **Billing**: Cashier Stripe (usage-based metered billing)
- **AI**: Laravel AI SDK (agents, streaming, events)

## 2. Existing Codebase (Do NOT Replace)

The following already exist and must be extended, not replaced:

| File | Purpose |
|------|---------|
| `app/Models/User.php` | User identity, Fortify auth, 2FA |
| `app/Models/Team.php` | Tenant/organization. `Team` = `Tenant` conceptually |
| `app/Models/Membership.php` | User-Team pivot with `role` column |
| `app/Models/TeamInvitation.php` | Pending invitations |
| `app/Enums/TeamRole.php` | owner, admin, member (will be extended) |
| `app/Enums/TeamPermission.php` | Existing permission enum |
| `app/Http/Middleware/EnsureTeamMembership.php` | Tenant gate middleware |
| `app/Http/Middleware/SetTeamUrlDefaults.php` | Sets `current_team` route default |
| `routes/web.php` | Team-scoped dashboard at `/{current_team}/dashboard` |
| `routes/settings.php` | Profile, security, teams CRUD, members, invitations |

## 3. Domain-Modular Architecture

### Directory Structure

```
app/
├── Contracts/                    # Cross-cutting interfaces
│   ├── ObjectStorage.php
│   └── KeyValueStore.php
├── Domains/
│   ├── Tenancy/
│   │   ├── Actions/
│   │   ├── Data/                 # DTOs
│   │   ├── Enums/
│   │   ├── Events/
│   │   ├── Jobs/
│   │   ├── Listeners/
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Services/
│   │   ├── Queries/
│   │   └── Support/
│   ├── Access/
│   ├── Integrations/
│   ├── Assets/
│   ├── Drivers/
│   ├── Ingestion/
│   ├── Normalization/
│   ├── Context/
│   ├── AI/
│   ├── Decisions/
│   ├── Incidents/
│   ├── Automation/
│   ├── Notifications/
│   ├── Audit/
│   ├── Analytics/
│   └── TenantConfig/
├── Infrastructure/
│   ├── Storage/                  # RustFsStorage implementation
│   ├── Broadcasting/             # Custom channel classes, SSE controllers
│   └── AI/                       # AI SDK agent definitions, usage listeners
├── Models/                       # Existing models (User, Team, etc.)
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
└── Providers/
```

### Domain Registration Pattern

Each domain has a ServiceProvider registered in `bootstrap/providers.php`:

```php
// app/Domains/Ingestion/IngestionServiceProvider.php
namespace App\Domains\Ingestion;

use Illuminate\Support\ServiceProvider;

class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind contracts to implementations
    }

    public function boot(): void
    {
        // Register event listeners
        // Register routes (if API endpoints exist)
    }
}
```

## 4. Shared Concerns

### Tenant Isolation Trait

Every domain model that belongs to a tenant MUST use this trait:

```php
// app/Concerns/BelongsToTenant.php
namespace App\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($team = currentTeam()) {
                $builder->where($builder->getModel()->getTable() . '.team_id', $team->id);
            }
        });

        static::creating(function ($model) {
            if (! $model->team_id && $team = currentTeam()) {
                $model->team_id = $team->id;
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
```

### Helper Function

```php
// app/Support/helpers.php (autoloaded via composer.json)
function currentTeam(): ?\App\Models\Team
{
    return auth()->user()?->currentTeam;
}
```

### Migration Convention for Tenant Tables

Every tenant-scoped table includes:

```php
$table->foreignId('team_id')->constrained()->cascadeOnDelete();
$table->index('team_id');
```

## 5. Infrastructure Configuration

### 5.1 Valkey (Sole KV Store)

Valkey replaces Redis entirely. Laravel's `redis` driver connects to Valkey transparently since the wire protocol is identical.

**`.env` variables:**
```
REDIS_CLIENT=phpredis
REDIS_HOST=valkey
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

**`config/database.php` redis connections** all point to `REDIS_HOST` (which resolves to the `valkey` Docker service).

Separate databases for isolation:
- `default` (db 0): general purpose
- `cache` (db 1): cache store
- `queue` (db 2): Horizon queues (via `REDIS_QUEUE_DB`)
- `sessions` (db 3): session storage

**Prefix strategy** to prevent ACL key pattern conflicts:
```php
'options' => [
    'prefix' => env('REDIS_PREFIX', 'sam_'),
],
```

### 5.2 RustFS (S3-Compatible Object Storage)

**`config/filesystems.php`:**
```php
'disks' => [
    'rustfs' => [
        'driver' => 's3',
        'key' => env('RUSTFS_ACCESS_KEY', 'sail'),
        'secret' => env('RUSTFS_SECRET_KEY', 'password'),
        'region' => env('RUSTFS_REGION', 'us-east-1'),
        'bucket' => env('RUSTFS_BUCKET', 'sam'),
        'url' => env('RUSTFS_URL'),
        'endpoint' => env('RUSTFS_ENDPOINT', 'http://rustfs:9000'),
        'use_path_style_endpoint' => true,
        'throw' => true,
    ],
],
```

**`.env` variables:**
```
RUSTFS_ACCESS_KEY=sail
RUSTFS_SECRET_KEY=password
RUSTFS_ENDPOINT=http://rustfs:9000
RUSTFS_BUCKET=sam
```

### 5.3 Soketi (WebSocket Server)

**`config/broadcasting.php`:**
```php
'connections' => [
    'pusher' => [
        'driver' => 'pusher',
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'app_id' => env('PUSHER_APP_ID'),
        'options' => [
            'host' => env('PUSHER_HOST', 'soketi'),
            'port' => env('PUSHER_PORT', 6001),
            'scheme' => env('PUSHER_SCHEME', 'http'),
            'encrypted' => false,
            'useTLS' => false,
        ],
    ],
],
```

**Channel naming conventions:**
- `private-accounts.{teamId}` -- tenant-scoped events (incidents, alerts, usage)
- `private-jobs.{jobId}` -- long-running job progress (AI streaming, exports)
- `presence-rooms.{roomId}` -- collaborative features (future)

**Channel auth** (`routes/channels.php`):
```php
Broadcast::channel('accounts.{teamId}', function ($user, $teamId) {
    return $user->belongsToTeam(Team::findOrFail($teamId));
});

Broadcast::channel('jobs.{jobId}', function ($user, $jobId) {
    // Validate user owns this job via DB lookup
    return true; // Replace with actual validation
});
```

### 5.4 Horizon (Queue Management)

Horizon connects to Valkey via the `redis` driver. **Does NOT support cluster mode** -- Valkey must run standalone.

**Queue topology by domain:**

| Queue Name | Domain(s) | Workers | Priority |
|------------|-----------|---------|----------|
| `default` | General | 2 | low |
| `ingestion` | Ingestion | 3 | high |
| `normalization` | Normalization | 2 | high |
| `context` | Context | 2 | medium |
| `ai-evaluation` | AI | 2 | medium |
| `decisions` | Decisions | 2 | high |
| `incidents` | Incidents | 2 | high |
| `automation` | Automation | 2 | medium |
| `notifications` | Notifications | 2 | medium |
| `audit` | Audit | 1 | low |
| `analytics` | Analytics | 1 | low |
| `billing` | Tenancy (usage) | 1 | medium |
| `sync` | Integrations | 2 | medium |

### 5.5 Laravel AI SDK

- Agent classes in `app/Infrastructure/AI/Agents/`
- SDK creates `agent_conversations` and `agent_conversation_messages` tables
- Bridge to tenants via `ai_conversation_links` table (team_id, user_id, agent_conversation_id)
- Listen to SDK events (`AgentPrompted`, `AgentStreamed`, `EmbeddingsGenerated`) in `AppServiceProvider` to generate `usage_events`

## 6. Usage Metering Pipeline (Cross-Cutting)

Every module that produces billable actions emits usage events through a shared action:

```php
// app/Domains/Tenancy/Actions/RecordUsageEvent.php
namespace App\Domains\Tenancy\Actions;

class RecordUsageEvent
{
    public function execute(
        int $teamId,
        string $meterCode,
        int $quantity,
        string $eventKey,       // Idempotency key (unique per team)
        ?array $metadata = null,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        // Insert into usage_events with unique constraint on (team_id, event_key)
        // Silently skip duplicates (idempotent)
    }
}
```

**Pipeline:**
1. **Emit** -- Modules call `RecordUsageEvent` at billable actions
2. **Aggregate** -- `AggregateUsageJob` runs daily via scheduler, rolls up into `usage_daily_aggregates`
3. **Report** -- On Stripe `invoice.upcoming` webhook, report to Stripe via `reportMeterEvent()`
4. **Conciliate** -- Store billing snapshots for audit

**Meters catalog:**

| Code | Unit | Source |
|------|------|--------|
| `api_requests` | request | HTTP middleware |
| `ai_tokens_in` | tokens | AI SDK `AgentStreamed` event |
| `ai_tokens_out` | tokens | AI SDK `AgentStreamed` event |
| `ai_calls` | call | AI evaluation job dispatch |
| `monitored_assets` | count | Daily snapshot job |
| `active_cameras` | count | Daily snapshot job |
| `outbound_notifications` | count | Notification delivery |
| `incident_workflows` | count | Automation execution |
| `stored_video_gb` | GB | Daily RustFS size aggregation |
| `generated_reports` | count | Report export |
| `active_users` | count | Daily unique active count |

## 7. Implementation Order

Modules MUST be implemented in this order due to dependencies:

| Phase | Specs | Rationale |
|-------|-------|-----------|
| **0 - Infrastructure** | I1 (Storage), I2 (Broadcasting), I3 (Key-Value) | Foundation services all modules depend on |
| **1 - Foundation** | 01 (Tenancy), 02 (Access), 16 (Tenant Config) | Tenant isolation, roles, configuration |
| **2 - External Data** | 03 (Integrations), 04 (Assets), 05 (Drivers) | External provider connections, synced entities |
| **3 - Event Pipeline** | 06 (Ingestion), 07 (Normalization) | Raw event capture and canonical transformation |
| **4 - Intelligence** | 08 (Context), 09 (AI), 10 (Decisions) | Enrichment, AI evaluation, rule engine |
| **5 - Operations** | 11 (Incidents), 12 (Automation), 13 (Notifications) | Operational response and communication |
| **6 - Observability** | 14 (Audit), 15 (Analytics) | System-wide tracing and metrics |

## 8. Conventions

### Naming

- **Models**: Singular PascalCase (`RawEvent`, `NormalizedEvent`, `AIEventEvaluation`)
- **Tables**: Plural snake_case (`raw_events`, `normalized_events`, `ai_event_evaluations`)
- **JSON columns**: Suffixed with `_json` (`payload_json`, `metadata_json`, `config_json`)
- **Enums**: PascalCase keys (`Active`, `Inactive`, `PendingProcessing`)
- **Actions**: Verb + noun (`StoreRawEvent`, `NormalizeRawEvent`, `EvaluateEventWithAI`)
- **Jobs**: Verb + noun + `Job` suffix (`NormalizeEventJob`, `EvaluateEventJob`)
- **Events**: Past tense (`RawEventReceived`, `EventNormalized`, `IncidentCreated`)
- **Broadcasting Events**: Past tense + `Broadcast` suffix only if needed to disambiguate

### Migrations

- Run `php artisan make:migration` with descriptive names
- Always include `team_id` foreign key on tenant-scoped tables
- Always add composite indexes for common query patterns
- Use `$table->id()` (auto-increment bigint) for primary keys
- Use `$table->timestamps()` on all tables
- Use `$table->softDeletes()` only on entities that need recovery (Team, Incident, Asset)

### Testing

- **Factory per model**: Every Eloquent model gets a factory
- **Feature test per action/service**: One PHPUnit test class per action
- **Test naming**: `test_it_creates_incident_from_decision_outcome`
- **Tenant isolation tests**: Assert that queries are scoped to the current team
- **Idempotency tests**: Assert that duplicate calls produce no side effects
- **Run command**: `php artisan test --compact --filter=TestClassName`
- **Code style**: Run `vendor/bin/pint --dirty --format agent` after changes

### Module Spec Structure (14 sections)

Each spec file follows this exact structure:

```
# [Module Name]
## 1. Purpose
## 2. Responsibilities
## 3. Inputs / Outputs
## 4. Entities
## 5. Services
## 6. Jobs
## 7. Domain Events
## 8. Broadcasting Events
## 9. APIs / Endpoints
## 10. Business Rules
## 11. Integration with Other Modules
## 12. Usage Metering
## 13. Technical Considerations
## 14. Test Scenarios
```

## 9. Implementation Steps Per Module

For each module spec, follow these steps in order:

1. Read the spec file completely
2. Create the domain directory: `mkdir -p app/Domains/{Name}/{Actions,Data,Enums,Events,Jobs,Listeners,Models,Policies,Services,Queries,Support}`
3. Create migrations: `php artisan make:migration create_xxx_table --no-interaction`
4. Create models: `php artisan make:model --no-interaction` then move to domain directory, add relationships/casts/scopes
5. Create factories: `php artisan make:factory --no-interaction`
6. Create enums in the domain `Enums/` directory
7. Create action classes in `Actions/`
8. Create service classes in `Services/`
9. Create job classes: `php artisan make:job --no-interaction` then move to domain
10. Create event classes: `php artisan make:event --no-interaction` then move to domain
11. Create listener classes in `Listeners/`
12. Create broadcasting events implementing `ShouldBroadcast`
13. Create controllers and form requests (if API endpoints exist)
14. Register routes in domain ServiceProvider or `routes/` files
15. Register channel auth in `routes/channels.php`
16. Create policies: `php artisan make:policy --no-interaction`
17. Wire usage metering (call `RecordUsageEvent` at billable action points)
18. Create the domain ServiceProvider and register it
19. Write PHPUnit tests
20. Run `vendor/bin/pint --dirty --format agent`
21. Run `php artisan test --compact` on the module's tests
22. Run migrations: `php artisan migrate`
