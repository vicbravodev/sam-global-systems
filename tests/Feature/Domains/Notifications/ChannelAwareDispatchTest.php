<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChannelAwareDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
    }

    public function test_sms_to_recipient_without_phone_is_skipped_not_emailed(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

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

        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame(DeliveryStatus::Skipped, $delivery->status);
        $this->assertStringContainsString('phone', (string) $delivery->error_message);
    }

    public function test_sms_to_recipient_with_phone_targets_the_phone(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

        $notification = Notification::factory()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => '+5215555550188'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertNotSame(DeliveryStatus::Skipped, $delivery->status);
        $this->assertSame('+5215555550188', $delivery->payload_json['address'] ?? null);
    }

    public function test_notification_with_only_skipped_deliveries_is_cancelled(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        // Single critical SMS channel, recipient has no phone: every channel is
        // skipped, so the dispatcher makes zero real attempts and cancels.
        NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

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

        $this->assertSame(NotificationStatus::Cancelled, $notification->refresh()->status);
    }

    public function test_one_delivered_one_skipped_keeps_notification_sent(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        // Email + SMS channels; recipient has an email but no phone. The email
        // delivers and the SMS is skipped — a skip must NOT degrade the overall
        // status to PartiallySent.
        NotificationChannel::factory()->email()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);
        NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

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

        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);

        $deliveries = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->get();

        $this->assertSame(1, $deliveries->where('status', DeliveryStatus::Delivered)->count());
        $this->assertSame(1, $deliveries->where('status', DeliveryStatus::Skipped)->count());
    }
}
