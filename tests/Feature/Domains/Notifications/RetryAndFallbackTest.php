<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RetryAndFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
        Mail::fake();
    }

    public function test_retry_job_marks_delivery_delivered_on_success(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $channel = NotificationChannel::factory()->email()->create(['team_id' => $team->id]);
        $notification = Notification::factory()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'address' => 'ops@example.com',
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

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(2, $delivery->attempt_number);
    }

    public function test_retry_backoff_is_exponential_for_default_channels(): void
    {
        $job = new RetryNotificationDeliveryJob(0);

        $this->assertSame([30, 60, 120, 300, 600], $job->backoff());
        $this->assertSame(5, $job->tries());
    }

    public function test_retry_backoff_is_capped_for_webhook_channel(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
        ]);

        $notification = Notification::factory()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
        ]);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $team->id,
        ]);

        $job = new RetryNotificationDeliveryJob($delivery->id);

        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertSame(3, $job->tries());
    }

    public function test_fallback_creates_delivery_on_alternate_channel(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $primary = NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Sms,
            'is_active' => true,
        ]);

        $fallback = NotificationChannel::factory()->email()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Email,
            'is_active' => true,
        ]);

        $notification = Notification::factory()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'address' => 'ops@example.com',
        ]);
        $failed = NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $primary->id,
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
            ->where('recipient_id', $recipient->id)
            ->where('channel_id', $fallback->id)
            ->first();

        $this->assertNotNull($fallbackDelivery);
        $this->assertSame(DeliveryStatus::Delivered, $fallbackDelivery->status);
    }
}
