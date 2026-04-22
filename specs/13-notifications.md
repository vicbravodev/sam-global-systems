# Notifications

## 1. Purpose

Deliver operational notifications across multiple channels (email, SMS, WhatsApp, push, Slack, webhook, in-app via Soketi) with templates, recipient resolution, delivery tracking, and retry logic. This domain separates the *intent* to notify from the *mechanics* of delivery — a single notification can fan out to multiple recipients across multiple channels with independent delivery tracking.

## 2. Responsibilities

- Manage a catalog of notification channels and their provider configurations
- Maintain per-channel notification templates with variable interpolation
- Resolve notification recipients from users, teams, queues, and external contacts
- Select the appropriate delivery channel(s) based on priority, preferences, and policies
- Track individual delivery attempts with status, timestamps, and provider message IDs
- Retry failed deliveries with exponential backoff and fallback to alternate channels
- Respect user preferences including quiet hours, muted notification types, and channel preferences
- Deliver in-app notifications in real time via Soketi WebSocket broadcasting
- Emit usage events for billing metered outbound notifications

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Automation module | Action execution requesting notification | Service call to `SendNotification` |
| Incidents module | Incident-related notification triggers | Domain event / service call |
| Decisions module | Decision notification actions | Via Automation |
| System | System-wide announcements, maintenance notices | Internal dispatch |
| Admin / API | Manual notification send | Inertia pages / API |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Email provider | Rendered email via configured driver | Laravel Mail |
| SMS provider | SMS message via configured driver | HTTP API |
| WhatsApp provider | WhatsApp message via configured driver | HTTP API |
| Push provider | Push notification via configured driver | HTTP API / Firebase |
| Slack | Slack message via webhook or API | HTTP POST |
| External webhook | Webhook POST with notification payload | HTTP POST |
| Frontend (Soketi) | In-app notification for connected users | `NotificationPushedBroadcast` on `private-users.{userId}` |
| Audit module | Notification lifecycle events | Domain events |
| Tenancy module | Usage metering for outbound deliveries | `RecordUsageEvent` |

## 4. Entities

### 4.1 Notification Channels (`notification_channels`)

Catalog of available delivery channels per tenant. System-wide channels have `team_id = null`.

```php
Schema::create('notification_channels', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->string('provider');
    $table->string('channel_type'); // enum
    $table->jsonb('config_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->boolean('supports_priority')->default(false);
    $table->boolean('supports_template')->default(true);
    $table->timestamps();
});
```

**Enum `ChannelType`**: `Email`, `Sms`, `Push`, `Whatsapp`, `Web`, `Slack`, `Webhook`

### 4.2 Notification Templates (`notification_templates`)

Per-channel message templates with variable placeholders.

```php
Schema::create('notification_templates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->string('channel_type');
    $table->string('event_type')->nullable();
    $table->string('priority')->nullable();
    $table->string('subject_template')->nullable();
    $table->text('body_template');
    $table->jsonb('variables_schema_json')->nullable();
    $table->string('locale')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['team_id', 'channel_type', 'event_type']);
});
```

### 4.3 Notifications (`notifications`)

The notification intent record — represents the decision to notify, independent of delivery mechanics.

```php
Schema::create('notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('source_type'); // enum
    $table->string('source_reference_id')->nullable();
    $table->string('notification_type');
    $table->string('priority'); // enum
    $table->string('status'); // enum
    $table->string('subject')->nullable();
    $table->text('body_preview')->nullable();
    $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
    $table->string('triggered_by_type'); // enum
    $table->unsignedBigInteger('triggered_by_id')->nullable();
    $table->timestamp('scheduled_at')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();

    $table->index(['team_id', 'status']);
});
```

**Enum `NotificationSourceType`**: `Incident`, `Decision`, `ActionExecution`, `Escalation`, `Manual`, `SystemEvent`

**Enum `NotificationPriority`**: `Low`, `Normal`, `High`, `Critical`

**Enum `NotificationStatus`**: `Pending`, `Queued`, `PartiallySent`, `Sent`, `Failed`, `Cancelled`

**Enum `NotificationTriggeredByType`**: `System`, `User`, `Automation`

### 4.4 Notification Recipients (`notification_recipients`)

Each recipient of a notification with their contact details and preferences.

```php
Schema::create('notification_recipients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
    $table->string('recipient_type'); // enum
    $table->string('recipient_reference_id')->nullable();
    $table->string('name')->nullable();
    $table->string('address');
    $table->string('channel_preference')->nullable();
    $table->string('role')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();
});
```

**Enum `RecipientType`**: `User`, `Team`, `Queue`, `ExternalContact`, `WebhookEndpoint`, `SlackChannel`

### 4.5 Notification Deliveries (`notification_deliveries`)

Individual delivery attempts per recipient per channel. One notification can produce many deliveries.

```php
Schema::create('notification_deliveries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
    $table->foreignId('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
    $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
    $table->string('provider_message_id')->nullable();
    $table->string('status'); // enum
    $table->unsignedTinyInteger('attempt_number')->default(1);
    $table->jsonb('payload_json')->nullable();
    $table->jsonb('response_json')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamp('failed_at')->nullable();
    $table->timestamps();

    $table->index(['notification_id', 'status']);
});
```

**Enum `DeliveryStatus`**: `Pending`, `Queued`, `Sending`, `Delivered`, `Failed`, `Bounced`, `Retrying`, `Cancelled`

### 4.6 Notification Preferences (`notification_preferences`)

Per-user or per-role notification settings within a tenant.

```php
Schema::create('notification_preferences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('role')->nullable();
    $table->string('notification_type');
    $table->jsonb('allowed_channels_json');
    $table->boolean('muted')->default(false);
    $table->jsonb('quiet_hours_json')->nullable();
    $table->jsonb('escalation_fallback_json')->nullable();
    $table->timestamps();
});
```

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `SendNotification` | Orchestrates the full notification pipeline: create `notifications` record, resolve recipients, select channels, render templates, create `notification_deliveries`, and dispatch delivery jobs. |
| `ResolveRecipients` | Given a notification source and type, determine all recipients — expanding teams to individual users, resolving on-call queues, and including external contacts. |
| `RenderTemplate` | Load the template for the given `(channel_type, event_type, locale)`, interpolate variables into subject and body, return the rendered content. |
| `SelectNotificationChannels` | Determine which channels to use for each recipient based on priority, preferences, tenant notification policy, and channel availability. Critical notifications use multiple channels simultaneously. |
| `RetryFailedNotification` | Re-queue a failed `notification_delivery` with incremented `attempt_number` and appropriate backoff. |
| `DispatchNotificationDelivery` | Send the rendered notification via the selected channel's provider. Handles provider-specific API calls and response parsing. |

## 6. Jobs

### `SendNotificationJob`

- **Queue**: `notifications`
- **Retry**: 3 attempts
- **Logic**:
  1. Load the `notifications` record
  2. Call `ResolveRecipients` to determine all recipients
  3. For each recipient, call `SelectNotificationChannels`
  4. For each recipient-channel pair, call `RenderTemplate` and create a `notification_deliveries` record
  5. Dispatch individual delivery via `DispatchNotificationDelivery`
  6. Update notification status based on delivery outcomes
  7. Emit `NotificationCreated` domain event

### `RetryNotificationDeliveryJob`

- **Queue**: `notifications`
- **Retry**: 5 attempts
- **Backoff**: `[30, 60, 120, 300, 600]` (exponential)
- **Logic**:
  1. Load the `notification_deliveries` record
  2. Re-attempt delivery via `DispatchNotificationDelivery`
  3. On success: update status to `delivered`, set `delivered_at`
  4. On failure: update status to `retrying` or `failed` (if retries exhausted)
  5. If retries exhausted and fallback configured, dispatch `FallbackNotificationChannelJob`

### `FallbackNotificationChannelJob`

- **Queue**: `notifications`
- **Logic**:
  1. Load the failed `notification_deliveries` record
  2. Determine fallback channel from preferences or tenant notification policy
  3. Create a new `notification_deliveries` record for the fallback channel
  4. Dispatch delivery via `DispatchNotificationDelivery`

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `NotificationCreated` | `SendNotificationJob` creates a notification with recipients and deliveries | `teamId`, `notificationId`, `notificationType`, `recipientCount` |
| `NotificationDelivered` | A `notification_deliveries` record transitions to `delivered` | `teamId`, `notificationId`, `deliveryId`, `channelType` |
| `NotificationFailed` | A `notification_deliveries` record transitions to `failed` after exhausting retries | `teamId`, `notificationId`, `deliveryId`, `channelType`, `errorMessage` |

## 8. Broadcasting Events

### `NotificationPushedBroadcast`

Broadcast on `private-users.{userId}` for in-app web notifications delivered via Soketi.

```php
namespace App\Domains\Notifications\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class NotificationPushedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $notificationId,
        public readonly string $notificationType,
        public readonly string $priority,
        public readonly ?string $subject = null,
        public readonly ?string $bodyPreview = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.pushed';
    }

    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'notification_type' => $this->notificationType,
            'priority' => $this->priority,
            'subject' => $this->subject,
            'body_preview' => $this->bodyPreview,
        ];
    }
}
```

**Channel auth** (`routes/channels.php`):

```php
Broadcast::channel('users.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

## 9. APIs / Endpoints

All tenant-scoped endpoints are prefixed with `/{current_team}` and protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/{current_team}/notifications` | `NotificationController@index` | List notifications (filterable by status, type, priority) |
| `GET` | `/{current_team}/notifications/{notification}` | `NotificationController@show` | View notification detail with recipients and deliveries |
| `POST` | `/{current_team}/notifications/send` | `NotificationController@send` | Send a manual notification |
| `GET` | `/{current_team}/notifications/templates` | `NotificationTemplateController@index` | List notification templates |
| `POST` | `/{current_team}/notifications/templates` | `NotificationTemplateController@store` | Create a custom template |
| `PUT` | `/{current_team}/notifications/templates/{template}` | `NotificationTemplateController@update` | Update a template |
| `GET` | `/{current_team}/notifications/channels` | `NotificationChannelController@index` | List available channels |
| `PUT` | `/{current_team}/notifications/channels/{channel}` | `NotificationChannelController@update` | Update channel config |
| `GET` | `/{current_team}/notifications/preferences` | `NotificationPreferenceController@index` | List notification preferences for current user |
| `PUT` | `/{current_team}/notifications/preferences` | `NotificationPreferenceController@update` | Update notification preferences |

## 10. Business Rules

1. **Intent vs. delivery separation** — A `notifications` record represents the intent to notify. Each `notification_deliveries` record represents an independent delivery attempt. A notification can be `sent` even if some deliveries fail, as long as at least one succeeds.
2. **Multi-recipient fan-out** — One notification can have multiple recipients. Each recipient gets their own `notification_recipients` record and one or more `notification_deliveries` records.
3. **Per-channel templates** — Templates are channel-specific. Email templates include subject and HTML body; SMS templates are short plaintext; push templates are title + short body. The `RenderTemplate` service selects the correct template by `(channel_type, event_type)`.
4. **Critical priority multi-channel** — Notifications with `priority = critical` are sent via all available channels simultaneously (email + SMS + push + in-app) regardless of user preferences.
5. **Quiet hours enforcement** — Non-critical notifications are suppressed during quiet hours defined in `notification_preferences.quiet_hours_json`. Suppressed notifications are queued and delivered when quiet hours end.
6. **Mute respects priority** — A muted notification type suppresses `low` and `normal` priority only. `High` and `critical` notifications are never muted.
7. **Fallback channel escalation** — If a delivery fails on the primary channel after all retries, and `escalation_fallback_json` is configured, the notification is re-sent on the fallback channel.
8. **Delivery deduplication** — The same notification is not delivered twice to the same recipient on the same channel. Enforced by checking existing `notification_deliveries` before creating new ones.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Automation** | Primary consumer — the Automation module calls `SendNotification` when executing `send_email`, `send_sms`, `send_whatsapp`, `send_push` actions. |
| **Incidents** | Incident notifications (created, escalated, resolved) flow through Automation into this module. |
| **Tenant Config** | Reads tenant notification policies via `ResolveTenantNotificationPolicy` to determine allowed channels, fallback rules, and quiet hours at the tenant level. |
| **Access** | Policies check team membership and permissions for managing templates and channels. |
| **Audit** | All domain events (`NotificationCreated`, `NotificationDelivered`, `NotificationFailed`) are captured for compliance. |
| **Tenancy** | `team_id` FK on tenant-scoped entities. Uses `BelongsToTenant` trait. Emits `outbound_notifications` usage events. |

## 12. Usage Metering

| Meter Code | When Recorded |
|------------|---------------|
| `outbound_notifications` | 1 event per `notification_deliveries` record created (via `RecordUsageEvent`) |

```php
app(RecordUsageEvent::class)->execute(
    teamId: $delivery->notification->team_id,
    meterCode: 'outbound_notifications',
    quantity: 1,
    eventKey: "notif_delivery_{$delivery->id}",
);
```

Each delivery attempt counts separately — if a notification goes to 3 recipients on 2 channels each, that's 6 metered events.

## 13. Technical Considerations

### Provider Abstraction

- Each `notification_channels.provider` maps to a driver class implementing a `NotificationDriver` interface with a `send(RenderedNotification $notification): DeliveryResult` method.
- Supported providers can be extended without modifying core logic.

### Template Rendering

- Templates use Blade syntax for variable interpolation: `{{ $incident_type }}`, `{{ $asset_name }}`.
- Variables are validated against `variables_schema_json` before rendering to prevent runtime errors.
- Rendered content is cached per `(template_id, variables_hash)` in Valkey with 5-minute TTL for repeated notifications.

### Performance

- Recipient resolution for large teams uses chunked queries to avoid memory issues.
- Delivery jobs are dispatched individually per recipient-channel pair to maximize parallelism on the `notifications` queue.
- In-app notifications via Soketi bypass the queue for instant delivery — they are broadcast inline after the `notifications` record is created.

### Quiet Hours

- Quiet hours are stored as `{ "start": "22:00", "end": "07:00", "timezone": "America/New_York" }`.
- The scheduler runs a `DeliverQueuedQuietHoursNotificationsJob` every 15 minutes to release notifications whose quiet hours have ended.

### Rate Limiting

- SMS and WhatsApp channels enforce per-tenant rate limits (configurable in `notification_channels.config_json`) to prevent provider throttling.
- Rate limiting is implemented via Valkey counters with sliding window.

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_notification_sent_to_multiple_recipients` | A notification with 3 recipients creates 3 `notification_recipients` and at least 3 `notification_deliveries` |
| `test_template_renders_with_variables` | `RenderTemplate` interpolates `{{ $incident_type }}` and `{{ $asset_name }}` correctly in the body |
| `test_critical_notification_uses_multiple_channels` | A `critical` priority notification creates deliveries on all available channels for the recipient |
| `test_failed_delivery_retries` | A failed delivery is retried up to 5 times with exponential backoff `[30, 60, 120, 300, 600]` |
| `test_quiet_hours_suppress_notification` | A `normal` priority notification during quiet hours is queued, not delivered immediately |
| `test_in_app_notification_broadcasts_via_soketi` | Creating a web-channel delivery dispatches `NotificationPushedBroadcast` on `private-users.{userId}` |
| `test_usage_event_emitted_per_delivery` | Each `notification_deliveries` creation emits an `outbound_notifications` usage event |
| `test_fallback_channel_used_after_primary_exhausted` | After primary channel retries are exhausted, `FallbackNotificationChannelJob` delivers on the fallback channel |
| `test_muted_notification_suppressed_for_low_priority` | A muted notification type with `low` priority is not delivered |
| `test_muted_notification_delivered_for_critical_priority` | A muted notification type with `critical` priority is still delivered |
| `test_delivery_deduplication_prevents_double_send` | Attempting to create a duplicate delivery for the same recipient-channel pair is silently skipped |
| `test_channel_selection_respects_user_preferences` | `SelectNotificationChannels` returns only channels in the user's `allowed_channels_json` |
| `test_manual_notification_creates_and_dispatches` | POSTing to the send endpoint creates a `notifications` record and dispatches `SendNotificationJob` |
