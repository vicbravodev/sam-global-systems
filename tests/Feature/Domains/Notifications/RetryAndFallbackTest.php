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
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

    public function test_failed_delivery_event_schedules_retry_with_first_backoff_delay(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Email);
        $delivery = $this->failedDelivery($channel, attemptNumber: 1);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 30,
        );
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);
    }

    public function test_retry_delay_follows_backoff_schedule_for_later_attempts(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Email);
        $delivery = $this->failedDelivery($channel, attemptNumber: 3);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 120,
        );
    }

    public function test_webhook_delivery_uses_webhook_backoff_delay(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Webhook);
        $delivery = $this->failedDelivery($channel, attemptNumber: 2);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 120,
        );
    }

    public function test_exhausted_retries_dispatch_fallback_job(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Email);
        $delivery = $this->failedDelivery($channel, attemptNumber: 5);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            FallbackNotificationChannelJob::class,
            fn (FallbackNotificationChannelJob $job) => $job->failedDeliveryId === $delivery->id,
        );
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
    }

    public function test_webhook_retries_exhaust_after_three_attempts(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Webhook);
        $delivery = $this->failedDelivery($channel, attemptNumber: 3);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            FallbackNotificationChannelJob::class,
            fn (FallbackNotificationChannelJob $job) => $job->failedDeliveryId === $delivery->id,
        );
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
    }

    public function test_stale_failure_event_for_delivered_delivery_is_ignored(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Email);
        $notification = Notification::factory()->create(['team_id' => $channel->team_id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $channel->team_id,
            'address' => 'ops@example.com',
        ]);
        $delivery = NotificationDelivery::factory()->delivered()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $channel->team_id,
            'attempt_number' => 1,
        ]);

        $this->fireFailureEvent($delivery);

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);
    }

    public function test_failed_send_during_dispatch_schedules_retry(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Email);
        $this->bindAlwaysFailingDriver();

        $notification = Notification::factory()->create([
            'team_id' => $channel->team_id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
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
            ->firstOrFail();

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 30,
        );
    }

    public function test_failed_retry_schedules_next_retry_with_increased_delay(): void
    {
        Queue::fake();

        $channel = $this->activeChannel(ChannelType::Email);
        $delivery = $this->failedDelivery($channel, attemptNumber: 1);
        $this->bindAlwaysFailingDriver();

        (new RetryNotificationDeliveryJob($delivery->id))->handle(
            app(ChannelDriverRegistry::class),
            app(RenderNotificationContent::class),
            app(RecordDeliveryAttempt::class),
            app(RecordUsageEvent::class),
        );

        $delivery->refresh();
        $this->assertSame(2, $delivery->attempt_number);
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 60,
        );
    }

    public function test_fallback_job_meter_is_idempotent_across_duplicate_runs(): void
    {
        $primary = $this->activeChannel(ChannelType::Sms);
        $fallbackChannel = NotificationChannel::factory()->email()->create([
            'team_id' => $primary->team_id,
            'is_active' => true,
        ]);
        $failed = $this->failedDelivery($primary, attemptNumber: 5);

        $run = fn () => (new FallbackNotificationChannelJob($failed->id))->handle(
            app(ChannelDriverRegistry::class),
            app(TenantNotificationPoliciesResolver::class),
            app(RenderNotificationContent::class),
            app(RecordDeliveryAttempt::class),
            app(RecordUsageEvent::class),
        );

        $run();
        $run();

        $fallbackDeliveries = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $failed->notification_id)
            ->where('channel_id', $fallbackChannel->id)
            ->get();

        $this->assertCount(1, $fallbackDeliveries);
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()
            ->where('event_key', "notif_fallback_{$fallbackDeliveries->first()->id}")
            ->count());
    }

    private function activeChannel(ChannelType $type): NotificationChannel
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $factory = NotificationChannel::factory();

        $factory = match ($type) {
            ChannelType::Email => $factory->email(),
            ChannelType::Sms => $factory->sms(),
            default => $factory->state(['channel_type' => $type, 'provider' => $type->value]),
        };

        return $factory->create([
            'team_id' => $user->currentTeam->id,
            'is_active' => true,
        ]);
    }

    private function failedDelivery(NotificationChannel $channel, int $attemptNumber): NotificationDelivery
    {
        $notification = Notification::factory()->create(['team_id' => $channel->team_id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $channel->team_id,
            'address' => 'ops@example.com',
        ]);

        return NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $channel->team_id,
            'attempt_number' => $attemptNumber,
        ]);
    }

    private function fireFailureEvent(NotificationDelivery $delivery): void
    {
        event(new NotificationFailed(
            $delivery->team_id,
            $delivery->notification_id,
            $delivery->id,
            NotificationChannel::withoutGlobalScopes()->find($delivery->channel_id)->channel_type->value,
            'provider bounced',
        ));
    }

    private function bindAlwaysFailingDriver(): void
    {
        $this->app->instance(ChannelDriverRegistry::class, new class implements ChannelDriverRegistry
        {
            public function driverFor(ChannelType $channelType): NotificationDriver
            {
                return new class implements NotificationDriver
                {
                    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
                    {
                        return DeliveryResult::failure('provider unavailable');
                    }
                };
            }
        });
    }
}
