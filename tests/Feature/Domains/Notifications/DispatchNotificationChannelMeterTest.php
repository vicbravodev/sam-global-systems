<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\Notifications\NotificationDriver;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsMeterSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Messaging deliveries (SMS / WhatsApp / voice) bill on per-channel meters so
 * the tenant pays the provider fee per channel; email/web stay on the generic
 * outbound_notifications meter (covered in DispatchNotificationTest).
 */
class DispatchNotificationChannelMeterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);
        // voice_calls (incident DTMF verification) must exist so the "voice
        // notifications do not bill the DTMF meter" assertions are meaningful.
        $this->seed(IncidentsMeterSeeder::class);

        $this->app->instance(ChannelDriverRegistry::class, new class implements ChannelDriverRegistry
        {
            public function driverFor(ChannelType $channelType): NotificationDriver
            {
                return new class implements NotificationDriver
                {
                    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
                    {
                        return DeliveryResult::success(providerMessageId: 'fake-message-id');
                    }
                };
            }
        });
    }

    public function test_sms_delivery_bills_sms_messages_meter(): void
    {
        $team = $this->actingTeam();
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = $this->makeMessagingNotification($team, ChannelType::Sms);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();

        $events = $this->usageEventsFor('sms_messages');
        $this->assertCount(1, $events);
        $this->assertSame("notif_delivery_{$delivery->id}", $events->first()->event_key);
        $this->assertCount(0, $this->usageEventsFor('outbound_notifications'));
    }

    public function test_whatsapp_delivery_bills_whatsapp_messages_meter(): void
    {
        $team = $this->actingTeam();
        NotificationChannel::factory()->whatsapp()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = $this->makeMessagingNotification($team, ChannelType::Whatsapp);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();

        $events = $this->usageEventsFor('whatsapp_messages');
        $this->assertCount(1, $events);
        $this->assertSame("notif_delivery_{$delivery->id}", $events->first()->event_key);
        $this->assertCount(0, $this->usageEventsFor('outbound_notifications'));
    }

    public function test_voice_delivery_bills_voice_notification_calls_not_dtmf_voice_calls(): void
    {
        $team = $this->actingTeam();
        NotificationChannel::factory()->voice()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = $this->makeMessagingNotification($team, ChannelType::Voice);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();

        $events = $this->usageEventsFor('voice_notification_calls');
        $this->assertCount(1, $events);
        $this->assertSame("notif_delivery_{$delivery->id}", $events->first()->event_key);
        $this->assertCount(0, $this->usageEventsFor('outbound_notifications'));
        // PlaceVerificationCallJob owns voice_calls (DTMF verification); a
        // voice notification must never inflate that meter.
        $this->assertCount(0, $this->usageEventsFor('voice_calls'));
    }

    public function test_re_dispatch_does_not_double_bill_channel_meter(): void
    {
        $team = $this->actingTeam();
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = $this->makeMessagingNotification($team, ChannelType::Sms);

        $dispatch = app(DispatchNotification::class);
        $dispatch->execute($notification);
        $dispatch->execute($notification);

        $this->assertCount(1, $this->usageEventsFor('sms_messages'));
    }

    public function test_retried_sms_delivery_bills_sms_messages_meter(): void
    {
        $team = $this->actingTeam();
        $channel = NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = Notification::factory()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'phone' => '+5215512345678',
        ]);
        $delivery = NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $team->id,
            'attempt_number' => 1,
        ]);

        (new RetryNotificationDeliveryJob($delivery->id))->handle(
            app(ChannelDriverRegistry::class),
            app(RenderNotificationContent::class),
            app(RecordDeliveryAttempt::class),
            app(RecordUsageEvent::class),
        );

        $events = $this->usageEventsFor('sms_messages');
        $this->assertCount(1, $events);
        $this->assertSame("notif_retry_{$delivery->id}_2", $events->first()->event_key);
        $this->assertCount(0, $this->usageEventsFor('outbound_notifications'));
    }

    public function test_fallback_to_sms_bills_sms_messages_meter(): void
    {
        $team = $this->actingTeam();

        $this->app->instance(TenantNotificationPoliciesResolver::class, new class implements TenantNotificationPoliciesResolver
        {
            public function resolve(Team $team): TenantNotificationPolicy
            {
                return new TenantNotificationPolicy(
                    allowedChannels: [ChannelType::Email, ChannelType::Sms],
                    criticalChannels: [ChannelType::Email, ChannelType::Sms],
                    fallbackChannels: [ChannelType::Sms],
                );
            }
        });

        $primaryChannel = NotificationChannel::factory()->email()->create(['team_id' => $team->id, 'is_active' => true]);
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true]);

        $notification = Notification::factory()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'phone' => '+5215512345678',
        ]);
        $failed = NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $primaryChannel->id,
            'team_id' => $team->id,
        ]);

        (new FallbackNotificationChannelJob($failed->id))->handle(
            app(ChannelDriverRegistry::class),
            app(TenantNotificationPoliciesResolver::class),
            app(RenderNotificationContent::class),
            app(RecordDeliveryAttempt::class),
            app(RecordUsageEvent::class),
        );

        $fallbackDelivery = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->where('id', '!=', $failed->id)
            ->sole();

        $events = $this->usageEventsFor('sms_messages');
        $this->assertCount(1, $events);
        $this->assertSame("notif_fallback_{$fallbackDelivery->id}", $events->first()->event_key);
        $this->assertCount(0, $this->usageEventsFor('outbound_notifications'));
    }

    private function actingTeam(): Team
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user->currentTeam;
    }

    private function makeMessagingNotification(Team $team, ChannelType $channelType): Notification
    {
        return Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'force_channels' => [$channelType->value],
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::ExternalContact->value,
                        'address' => 'ops@example.com',
                        'phone' => '+5215512345678',
                        'name' => 'Ops',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return Collection<int, UsageEvent>
     */
    private function usageEventsFor(string $meterCode): Collection
    {
        $meterId = UsageMeter::query()->where('code', $meterCode)->value('id');

        if ($meterId === null) {
            return new Collection;
        }

        return UsageEvent::withoutGlobalScopes()->where('usage_meter_id', $meterId)->get();
    }
}
