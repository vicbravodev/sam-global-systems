# Real-Time Broadcasting (Soketi)

## 1. Purpose

Configure Soketi as the Pusher-compatible WebSocket server for real-time event broadcasting to frontend clients, with SSE as a fallback for AI streaming.

## 2. Soketi Configuration

### Docker Service (already in compose.yaml)

```yaml
soketi:
    image: 'quay.io/soketi/soketi:latest-16-alpine'
    environment:
        SOKETI_DEBUG: '${SOKETI_DEBUG:-1}'
        SOKETI_METRICS_SERVER_PORT: '9601'
        SOKETI_DEFAULT_APP_ID: '${PUSHER_APP_ID}'
        SOKETI_DEFAULT_APP_KEY: '${PUSHER_APP_KEY}'
        SOKETI_DEFAULT_APP_SECRET: '${PUSHER_APP_SECRET}'
    ports:
        - '${PUSHER_PORT:-6001}:6001'
        - '${PUSHER_METRICS_PORT:-9601}:9601'
```

For horizontal scaling, add Valkey adapter:

```
SOKETI_ADAPTER_DRIVER=redis
SOKETI_ADAPTER_REDIS_HOST=valkey
SOKETI_ADAPTER_REDIS_PORT=6379
```

### Laravel Broadcasting Config

`config/broadcasting.php`:

```php
'default' => env('BROADCAST_CONNECTION', 'pusher'),

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

### Environment Variables

```
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=sam-local
PUSHER_APP_KEY=sam-key
PUSHER_APP_SECRET=sam-secret
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="${PUSHER_HOST}"
VITE_PUSHER_PORT="${PUSHER_PORT}"
VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
```

## 3. Channel Conventions

### Private Channels (require authentication)

| Channel Pattern | Purpose | Auth Rule |
|----------------|---------|-----------|
| `private-accounts.{teamId}` | Tenant-scoped events (incidents, alerts, usage updates, asset changes) | User must be a member of the team |
| `private-jobs.{jobId}` | Long-running job progress (AI streaming, report exports, sync progress) | User must own or have dispatched the job |
| `private-users.{userId}` | Personal notifications and activity | User ID must match |

### Presence Channels (authenticated + member tracking)

| Channel Pattern | Purpose | Auth Rule |
|----------------|---------|-----------|
| `presence-incidents.{incidentId}` | Collaborative incident review | User must have access to incident's team |

### Channel Authorization

`routes/channels.php`:

```php
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

// Tenant-scoped channel
Broadcast::channel('accounts.{teamId}', function ($user, int $teamId) {
    $team = Team::find($teamId);
    return $team && $user->belongsToTeam($team);
});

// Job progress channel
Broadcast::channel('jobs.{jobId}', function ($user, string $jobId) {
    // Validate via cache key or DB that user owns this job
    return cache()->get("job:{$jobId}:user") === $user->id;
});

// Personal notification channel
Broadcast::channel('users.{userId}', function ($user, int $userId) {
    return $user->id === $userId;
});

// Incident collaboration presence
Broadcast::channel('incidents.{incidentId}', function ($user, int $incidentId) {
    $incident = \App\Domains\Incidents\Models\Incident::find($incidentId);
    if (! $incident || ! $user->belongsToTeam($incident->team)) {
        return false;
    }
    return ['id' => $user->id, 'name' => $user->name];
});
```

## 4. Broadcasting Events Pattern

All broadcasting events implement `ShouldBroadcast` and are dispatched via queue:

```php
namespace App\Domains\Incidents\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class IncidentCreatedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $incidentId,
        public readonly string $title,
        public readonly string $priority,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'incident.created';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'title' => $this->title,
            'priority' => $this->priority,
        ];
    }
}
```

## 5. SSE Fallback for AI Streaming

For AI agent streaming, provide an SSE endpoint as fallback when WebSockets are unavailable:

```php
// Route: GET /api/{current_team}/ai/tasks/{taskId}/stream
// Returns: StreamableAgentResponse (from AI SDK) as SSE
```

The primary path is WebSocket broadcasting from a queued job (see spec 09-ai.md).

## 6. Frontend Integration

React client uses `@laravel/echo` with Pusher driver:

```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: import.meta.env.VITE_PUSHER_HOST,
    wsPort: import.meta.env.VITE_PUSHER_PORT,
    wssPort: import.meta.env.VITE_PUSHER_PORT,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: 'mt1',
});

// Listen for tenant events
echo.private(`accounts.${teamId}`)
    .listen('.incident.created', (e) => {
        console.log('New incident:', e);
    });
```

## 7. Observability

Soketi exposes Prometheus metrics at `http://soketi:9601/metrics`. Scrape this endpoint for:

- `soketi_connected` -- current WS connections
- `soketi_messages_sent` -- messages sent
- `soketi_messages_received` -- messages received

## 8. Testing

In tests, use `Event::fake()` to assert broadcasting events were dispatched:

```php
Event::fake([IncidentCreatedBroadcast::class]);

// perform action that creates incident

Event::assertDispatched(IncidentCreatedBroadcast::class, function ($event) use ($incident) {
    return $event->incidentId === $incident->id;
});
```
