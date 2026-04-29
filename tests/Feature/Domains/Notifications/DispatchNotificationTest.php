<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationCreated;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationPushedBroadcast;
use App\Domains\Notifications\Mail\GenericNotificationMail;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DispatchNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
    }

    public function test_email_delivery_creates_records_and_emits_usage(): void
    {
        Mail::fake();
        Event::fake([
            NotificationCreated::class,
            NotificationDelivered::class,
            UsageRecorded::class,
        ]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'subject' => 'Hello',
            'body_preview' => 'Hello body',
            'payload_json' => [
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::ExternalContact->value,
                        'address' => 'ops@example.com',
                        'name' => 'Ops',
                    ],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
        $this->assertSame(1, NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->count());

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);

        Mail::assertSent(GenericNotificationMail::class);
        Event::assertDispatched(NotificationCreated::class);
        Event::assertDispatched(NotificationDelivered::class);
        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'outbound_notifications');
    }

    public function test_does_not_create_duplicate_delivery_for_same_recipient_channel(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $channel = NotificationChannel::factory()->email()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'address' => 'ops@example.com',
        ]);

        NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $team->id,
            'status' => DeliveryStatus::Delivered,
        ]);

        // Re-dispatch — should not duplicate the (notification, recipient, channel) row.
        app(DispatchNotification::class)->execute($notification);

        $count = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->where('recipient_id', $recipient->id)
            ->where('channel_id', $channel->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_critical_notification_uses_multiple_channels(): void
    {
        Mail::fake();
        Event::fake([NotificationDelivered::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create(['team_id' => $team->id, 'is_active' => true]);
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true, 'channel_type' => ChannelType::Sms]);

        $notification = Notification::factory()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $deliveries = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->get();
        $this->assertGreaterThanOrEqual(2, $deliveries->count());
    }

    public function test_web_channel_broadcasts_notification_pushed(): void
    {
        Event::fake([NotificationPushedBroadcast::class, NotificationDelivered::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->web()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.web',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::User->value,
                        'address' => $user->email,
                        'recipient_reference_id' => (string) $user->id,
                        'channel_preference' => ChannelType::Web->value,
                    ],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        Event::assertDispatched(NotificationPushedBroadcast::class, function (NotificationPushedBroadcast $event) use ($user, $notification) {
            return $event->userId === $user->id
                && $event->notificationId === $notification->id
                && $event->notificationType === 'manual.web';
        });
    }

    public function test_muted_low_priority_notification_yields_no_deliveries(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create(['team_id' => $team->id, 'is_active' => true]);

        NotificationPreference::factory()->muted()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'notification_type' => 'manual.muted',
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.muted',
            'priority' => NotificationPriority::Low,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::User->value,
                        'address' => $user->email,
                        'recipient_reference_id' => (string) $user->id,
                    ],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $count = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count();

        $this->assertSame(0, $count);
        $this->assertSame(NotificationStatus::Cancelled, $notification->refresh()->status);
    }
}
