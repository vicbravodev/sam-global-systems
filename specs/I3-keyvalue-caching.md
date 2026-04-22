# Key-Value Store & Caching (Valkey)

## 1. Purpose

Configure Valkey as the sole key-value store for SAM, handling cache, sessions, queue backend, atomic locks, rate limiting, and Soketi Pub/Sub. No Redis instance exists.

## 2. Architecture Decision

**Valkey only. No Redis.** Valkey is wire-compatible with the Redis protocol. Laravel's `redis` driver, Horizon, and Soketi connect to Valkey transparently without any code changes.

## 3. Docker Service (already in compose.yaml)

```yaml
valkey:
    image: 'valkey/valkey:alpine'
    ports:
        - '${FORWARD_VALKEY_PORT:-6379}:6379'
    volumes:
        - 'sail-valkey:/data'
    healthcheck:
        test: ["CMD", "valkey-cli", "ping"]
        retries: 3
        timeout: 5s
```

## 4. Laravel Configuration

### `config/database.php` -- Redis Section

All `redis` connections point to Valkey:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'options' => [
        'prefix' => env('REDIS_PREFIX', 'sam_'),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', 'valkey'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', 'valkey'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],

    'queue' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', 'valkey'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_QUEUE_DB', '2'),
    ],

    'sessions' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', 'valkey'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_SESSION_DB', '3'),
    ],
],
```

### Environment Variables

```
REDIS_CLIENT=phpredis
REDIS_HOST=valkey
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_PREFIX=sam_
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

## 5. Roles and Usage

### Cache (db 1)

- Application cache (`Cache::put()`, `Cache::remember()`)
- Rate limiting counters (Laravel's `RateLimiter`)
- Tenant config resolution cache
- Broadcasting channel auth tokens

### Sessions (db 3)

- User session storage
- Session blocking for `WithoutOverlapping` middleware

### Queues (db 2)

- All job queues managed by Horizon
- Named queues per domain (see Master Guide queue topology)

### Locks (db 0)

- `ShouldBeUnique` job locks
- `WithoutOverlapping` middleware locks
- Custom distributed locks for idempotency (usage event dedup, webhook processing)

### Soketi Pub/Sub (db 0)

- Soketi adapter uses default connection for Pub/Sub between Soketi nodes
- Configure: `SOKETI_ADAPTER_DRIVER=redis`, `SOKETI_ADAPTER_REDIS_HOST=valkey`

## 6. Horizon Configuration

`config/horizon.php`:

```php
'use' => 'default',  // Redis connection name
'prefix' => env('HORIZON_PREFIX', 'sam_horizon:'),

'environments' => [
    'production' => [
        'supervisor-high' => [
            'connection' => 'redis',
            'queue' => ['ingestion', 'normalization', 'decisions', 'incidents'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'tries' => 3,
            'timeout' => 300,
        ],
        'supervisor-medium' => [
            'connection' => 'redis',
            'queue' => ['context', 'ai-evaluation', 'automation', 'notifications', 'billing', 'sync'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'tries' => 3,
            'timeout' => 600,
        ],
        'supervisor-low' => [
            'connection' => 'redis',
            'queue' => ['audit', 'analytics', 'default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'tries' => 3,
            'timeout' => 900,
        ],
    ],
    'local' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => [
                'ingestion', 'normalization', 'decisions', 'incidents',
                'context', 'ai-evaluation', 'automation', 'notifications',
                'billing', 'sync', 'audit', 'analytics', 'default',
            ],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],
],
```

**Critical**: Horizon does NOT support Redis Cluster. Valkey MUST run in standalone mode.

## 7. Rate Limiting

Define rate limiters in `AppServiceProvider::boot()` or a dedicated `RateLimitServiceProvider`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

// API rate limit per tenant
RateLimiter::for('api', function ($request) {
    $teamId = $request->route('current_team');
    return Limit::perMinute(60)->by($teamId ?: $request->ip());
});

// Webhook ingestion rate limit per provider
RateLimiter::for('webhooks', function ($request) {
    return Limit::perMinute(300)->by($request->ip());
});
```

## 8. Atomic Locks Pattern

For idempotent operations (usage events, webhook dedup):

```php
use Illuminate\Support\Facades\Cache;

$lock = Cache::lock("process-event:{$eventKey}", 30);

if ($lock->get()) {
    try {
        // Critical section
    } finally {
        $lock->release();
    }
}
```

## 9. Key Namespace Strategy

All keys are prefixed with `sam_` (via `REDIS_PREFIX`) to prevent ACL conflicts:

- Cache keys: `sam_cache:*`
- Queue keys: `sam_horizon:*` and `sam_queues:*`
- Lock keys: `sam_lock:*`
- Session keys: `sam_session:*`

## 10. Testing

In tests, Valkey is not mocked. Use the `RefreshDatabase` trait and let the test database handle state. For lock/cache-dependent tests, use `Cache::store('array')` or ensure the test Redis/Valkey is available.

## 11. Persistence

For production, enable AOF persistence in Valkey config:

```
appendonly yes
appendfsync everysec
```

This ensures queue and session data survives restarts.
