<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\Notifications\NotificationDriver;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Retry / fallback escalation of failed deliveries (spec 13): per-channel
 * business attempts and delays, the exact original payload on retry,
 * permanent failures jumping straight to the fallback channel, tenant channel
 * toggles, type-level dedup, and no escalation once it is pointless
 * (incident handled, notification expired, recipient already reached).
 */
class RetryAndFallbackTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private Team $team;

    /**
     * @var list<RenderedNotification>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);
        Mail::fake();

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->team = $user->currentTeam;
    }

    public function test_retry_job_marks_delivery_delivered_on_success(): void
    {
        $channel = $this->channel(ChannelType::Email);
        $delivery = $this->failedDelivery($channel, attemptNumber: 1);

        $this->runRetry($delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(2, $delivery->attempt_number);
    }

    public function test_retry_resends_the_exact_original_payload(): void
    {
        $this->bindCapturingDriver(DeliveryResult::success('ok'));

        $channel = $this->channel(ChannelType::Sms);
        $delivery = $this->failedDelivery($channel, attemptNumber: 1, payload: [
            'address' => '+5215512345678',
            'subject' => null,
            'body' => "Pánico unidad 7\nResponde SI-AB12 confirma / NO-AB12 descarta / ESC-AB12 escala",
        ]);

        $this->runRetry($delivery);

        // The recipient's generic address is an email: re-rendering would
        // have texted that. The retry sends the stored payload verbatim.
        $this->assertCount(1, $this->sent);
        $this->assertSame('+5215512345678', $this->sent[0]->address);
        $this->assertStringContainsString('SI-AB12', $this->sent[0]->body);

        $this->assertSystemLogged('notifications.delivery.sent', fn (array $c) => $c['input']['delivery_id'] === $delivery->id
            && $c['input']['stage'] === 'retry'
            && $c['input']['attempt_number'] === 2);
        $this->assertStringNotContainsString('5215512345678', json_encode($this->systemLogEntries()));
        $this->assertStringNotContainsString('SI-AB12', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_retry_backoff_is_exponential_for_default_channels(): void
    {
        $job = new RetryNotificationDeliveryJob(0);

        $this->assertSame([30, 60, 120, 300, 600], $job->retryDelays());
        $this->assertSame(5, $job->maxAttempts());
    }

    public function test_retry_and_fallback_jobs_never_rerun_at_queue_level(): void
    {
        // Business attempts live in attempt_number; a queue-level retry after a
        // real send would re-send (and re-bill) the message.
        $this->assertSame(1, (new RetryNotificationDeliveryJob(0))->tries);
        $this->assertSame(1, (new FallbackNotificationChannelJob(0))->tries);
    }

    public function test_retry_backoff_is_capped_for_webhook_channel(): void
    {
        $delivery = $this->failedDelivery($this->channel(ChannelType::Webhook), attemptNumber: 1);

        $job = new RetryNotificationDeliveryJob($delivery->id);

        $this->assertSame([30, 120, 600], $job->retryDelays());
        $this->assertSame(3, $job->maxAttempts());
    }

    public function test_fallback_creates_delivery_on_alternate_channel(): void
    {
        $primary = $this->channel(ChannelType::Sms);
        $fallback = $this->channel(ChannelType::Email);
        $failed = $this->failedDelivery($primary, attemptNumber: 5);

        $this->runFallback($failed);

        $fallbackDelivery = NotificationDelivery::query()
            ->where('notification_id', $failed->notification_id)
            ->where('channel_id', $fallback->id)
            ->sole();

        $this->assertSame(DeliveryStatus::Delivered, $fallbackDelivery->status);
        $this->assertSame('ops@example.com', $fallbackDelivery->payload_json['address']);

        $context = $this->assertSystemLogged('notifications.fallback.chosen', fn (array $c) => $c['input']['failed_delivery_id'] === $failed->id
            && $c['result']['delivery_id'] === $fallbackDelivery->id
            && $c['result']['channel_type'] === 'email'
            && $c['result']['channel_id'] === $fallback->id);
        $this->assertContains('sms', $context['calc']['used_types']);
        $this->assertSame(['channel_type' => 'email', 'outcome' => 'chosen'], end($context['calc']['walk']));
        $this->assertSystemLogged('notifications.delivery.sent', fn (array $c) => $c['input']['delivery_id'] === $fallbackDelivery->id
            && $c['input']['stage'] === 'fallback');
        $this->assertSystemNotLogged('notifications.fallback.exhausted');
        $this->assertStringNotContainsString('ops@example.com', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_failed_delivery_event_schedules_retry_with_first_backoff_delay(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 30,
        );
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);

        $context = $this->assertRetryScheduledMatchesPushedDelay($delivery);
        $this->assertSame(0, $context['calc']['step']);
        $this->assertSame(30, $context['result']['delay_seconds']);
        $this->assertSame(2, $context['result']['next_attempt_number']);
        $this->assertTrue($context['result']['job_requested']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_retry_delay_follows_backoff_schedule_for_later_attempts(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 3);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 120,
        );

        $context = $this->assertRetryScheduledMatchesPushedDelay($delivery);
        $this->assertSame(120, $context['result']['delay_seconds']);
        $this->assertSame(5, $context['calc']['max_attempts']);
    }

    public function test_webhook_delivery_uses_webhook_backoff_delay(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Webhook), attemptNumber: 2);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 120,
        );

        $context = $this->assertRetryScheduledMatchesPushedDelay($delivery);
        $this->assertSame([30, 120, 600], $context['calc']['delays_seconds']);
        $this->assertSame(3, $context['calc']['max_attempts']);
    }

    public function test_exhausted_retries_dispatch_fallback_job(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 5);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(
            FallbackNotificationChannelJob::class,
            fn (FallbackNotificationChannelJob $job) => $job->failedDeliveryId === $delivery->id,
        );
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);

        $this->assertSystemLogged('notifications.fallback.requested', fn (array $c) => $c['input']['delivery_id'] === $delivery->id
            && $c['input']['channel_type'] === 'email'
            && $c['calc']['trigger'] === 'retries_exhausted'
            && $c['calc']['attempt_number'] === 5
            && $c['calc']['max_attempts'] === 5
            && $c['result']['job_requested'] === true);
        $this->assertSystemNotLogged('notifications.retry.scheduled');
    }

    public function test_webhook_retries_exhaust_after_three_attempts(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Webhook), attemptNumber: 3);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(FallbackNotificationChannelJob::class);
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
    }

    public function test_permanent_failure_skips_retries_and_goes_to_fallback(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Sms), attemptNumber: 1);
        $delivery->update(['permanent_failure' => true, 'provider_error_code' => '21211']);

        $this->fireFailureEvent($delivery);

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertPushed(
            FallbackNotificationChannelJob::class,
            fn (FallbackNotificationChannelJob $job) => $job->failedDeliveryId === $delivery->id,
        );

        $this->assertSystemLogged('notifications.fallback.requested', fn (array $c) => $c['input']['delivery_id'] === $delivery->id
            && $c['calc']['trigger'] === 'permanent_failure'
            && $c['calc']['attempt_number'] === 1);
    }

    public function test_retry_job_never_resends_a_permanent_failure(): void
    {
        $this->bindCapturingDriver(DeliveryResult::success('ok'));

        $delivery = $this->failedDelivery($this->channel(ChannelType::Sms), attemptNumber: 1);
        $delivery->update(['permanent_failure' => true]);

        $this->runRetry($delivery);

        $this->assertSame([], $this->sent);
        $this->assertSame(1, $delivery->fresh()->attempt_number);

        $this->assertSystemLogged('notifications.retry.skipped', fn (array $c) => $c['reason'] === 'permanent_failure'
            && $c['input'] === ['delivery_id' => $delivery->id, 'stage' => 'retry_job']);
        $this->assertSystemNotLogged('notifications.escalation_guard.blocked');
        $this->assertSystemNotLogged('notifications.delivery.sent');
    }

    public function test_stale_failure_event_for_delivered_delivery_is_ignored(): void
    {
        Queue::fake();

        $channel = $this->channel(ChannelType::Email);
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = $this->recipient($notification);
        $delivery = NotificationDelivery::factory()->delivered()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $this->team->id,
        ]);

        $this->fireFailureEvent($delivery);

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);

        $this->assertSystemLogged('notifications.retry.skipped', fn (array $c) => $c['reason'] === 'not_failed'
            && $c['input'] === ['delivery_id' => $delivery->id, 'stage' => 'listener']);
        $this->assertSame('debug', $this->systemLogEntries('notifications.retry.skipped')[0]['level']);
    }

    public function test_no_retry_or_fallback_once_the_incident_was_acknowledged(): void
    {
        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);
        $delivery = $this->failedDelivery($this->channel(ChannelType::Sms), attemptNumber: 1, notificationAttributes: [
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
        ]);
        $incident->forceFill(['acknowledged_at' => now()])->save();

        $this->fireFailureEvent($delivery);

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);

        $this->assertSystemLogged('notifications.escalation_guard.blocked', fn (array $c) => $c['reason'] === 'incident_handled'
            && $c['input'] === ['delivery_id' => $delivery->id, 'notification_id' => $delivery->notification_id, 'stage' => 'listener']
            && $c['calc']['blocked_reason'] === 'incident_handled'
            && $c['calc']['incident_id'] === $incident->id
            && $c['calc']['incident_handled_at_present'] === true
            && $c['calc']['ttl_minutes'] === 30);
        $this->assertSystemNotLogged('notifications.retry.scheduled');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_delayed_retry_does_not_send_if_the_incident_got_closed_meanwhile(): void
    {
        $this->seed(IncidentStatusSeeder::class);
        $this->bindCapturingDriver(DeliveryResult::success('ok'));

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);
        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1, notificationAttributes: [
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
        ]);
        $incident->forceFill(['closed_at' => now()->addSecond()])->save();

        $this->runRetry($delivery);

        $this->assertSame([], $this->sent);

        $this->assertSystemLogged('notifications.escalation_guard.blocked', fn (array $c) => $c['reason'] === 'incident_handled'
            && $c['input']['stage'] === 'retry_job'
            && $c['calc']['incident_id'] === $incident->id);
    }

    public function test_notifications_created_after_the_acknowledgement_still_retry(): void
    {
        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        // e.g. the "incident acknowledged" notification itself.
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'acknowledged_at' => now()->subMinute(),
        ]);
        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1, notificationAttributes: [
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
        ]);

        $this->fireFailureEvent($delivery);

        Queue::assertPushed(RetryNotificationDeliveryJob::class);
    }

    public function test_suspended_tenant_gets_no_retry_nor_fallback(): void
    {
        $this->bindCapturingDriver(DeliveryResult::success('ok'));

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1);
        $this->channel(ChannelType::Web);
        Subscription::factory()->create([
            'team_id' => $this->team->id,
            'status' => SubscriptionStatus::Suspended,
        ]);

        $this->runRetry($delivery);
        $this->runFallback($delivery);

        $this->assertSame([], $this->sent);
        $this->assertSame(1, NotificationDelivery::query()->where('notification_id', $delivery->notification_id)->count());

        Queue::fake();
        $this->fireFailureEvent($delivery);
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);

        foreach (['retry_job', 'fallback_job', 'listener'] as $stage) {
            $this->assertSystemLogged('notifications.escalation_guard.blocked', fn (array $c) => $c['reason'] === 'tenant_cannot_send'
                && $c['calc']['blocked_reason'] === 'subscription_suspended'
                && $c['input']['stage'] === $stage);
        }
    }

    public function test_expired_notifications_are_not_escalated(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1, notificationAttributes: [
            'created_at' => now()->subHour(),
        ]);

        $this->fireFailureEvent($delivery);

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);

        $context = $this->assertSystemLogged('notifications.escalation_guard.blocked', fn (array $c) => $c['reason'] === 'expired');
        $this->assertGreaterThan($context['calc']['ttl_minutes'] * 60, $context['calc']['notification_age_seconds']);
    }

    public function test_failed_send_during_dispatch_schedules_retry(): void
    {
        Queue::fake();

        $this->channel(ChannelType::Email);
        $this->bindCapturingDriver(DeliveryResult::failure('provider unavailable'));

        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
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

        $delivery = NotificationDelivery::query()->where('notification_id', $notification->id)->firstOrFail();

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 30,
        );
    }

    public function test_failed_retry_schedules_next_retry_with_increased_delay(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1);
        $this->bindCapturingDriver(DeliveryResult::failure('provider unavailable'));

        $this->runRetry($delivery);

        $delivery->refresh();
        $this->assertSame(2, $delivery->attempt_number);
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === 60,
        );

        $this->assertSystemLogged('notifications.delivery.failed', fn (array $c) => $c['input']['delivery_id'] === $delivery->id
            && $c['input']['stage'] === 'retry'
            && $c['input']['attempt_number'] === 2
            && $c['reason'] === 'transient_failure');
        $this->assertSystemNotLogged('notifications.delivery.sent');
        $this->assertStringNotContainsString('provider unavailable', json_encode($this->systemLogEntries()));
    }

    public function test_failed_fallback_send_is_logged_with_the_fallback_stage(): void
    {
        Queue::fake();

        $primary = $this->channel(ChannelType::Sms);
        $email = $this->channel(ChannelType::Email);
        $failed = $this->failedDelivery($primary, attemptNumber: 5);
        $this->bindCapturingDriver(DeliveryResult::failure('provider unavailable'));

        $this->runFallback($failed);

        $fallbackDelivery = NotificationDelivery::query()->where('channel_id', $email->id)->sole();
        $this->assertSame(DeliveryStatus::Failed, $fallbackDelivery->status);

        $this->assertSystemLogged('notifications.delivery.failed', fn (array $c) => $c['input']['delivery_id'] === $fallbackDelivery->id
            && $c['input']['stage'] === 'fallback'
            && $c['input']['attempt_number'] === 1);
        $this->assertSystemLogged('notifications.fallback.chosen', fn (array $c) => $c['result']['delivery_id'] === $fallbackDelivery->id);
        $this->assertStringNotContainsString('provider unavailable', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_failed_retry_is_not_metered(): void
    {
        Queue::fake();

        $delivery = $this->failedDelivery($this->channel(ChannelType::Email), attemptNumber: 1);
        $this->bindCapturingDriver(DeliveryResult::failure('provider unavailable'));

        $this->runRetry($delivery);

        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_fallback_job_meter_is_idempotent_across_duplicate_runs(): void
    {
        $primary = $this->channel(ChannelType::Sms);
        $fallbackChannel = $this->channel(ChannelType::Email);
        $failed = $this->failedDelivery($primary, attemptNumber: 5);

        $this->runFallback($failed);
        $this->runFallback($failed);

        $fallbackDeliveries = NotificationDelivery::query()
            ->where('notification_id', $failed->notification_id)
            ->where('channel_id', $fallbackChannel->id)
            ->get();

        $this->assertCount(1, $fallbackDeliveries);
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()
            ->where('event_key', "notif_fallback_{$fallbackDeliveries->first()->id}")
            ->count());
    }

    public function test_fallback_respects_channels_the_tenant_switched_off(): void
    {
        $primary = $this->channel(ChannelType::Sms);
        $email = $this->channel(ChannelType::Email);

        TenantChannelToggle::factory()->disabled()->create([
            'team_id' => $this->team->id,
            'notification_channel_id' => $email->id,
        ]);

        $failed = $this->failedDelivery($primary, attemptNumber: 5);

        $this->runFallback($failed);

        $this->assertSame(0, NotificationDelivery::query()->where('channel_id', $email->id)->count());
    }

    public function test_fallback_dedups_by_channel_type_for_the_recipient(): void
    {
        $this->usePolicy(fallback: [ChannelType::Email, ChannelType::Sms]);

        $primary = $this->channel(ChannelType::Whatsapp);
        $emailA = $this->channel(ChannelType::Email);
        $emailB = $this->channel(ChannelType::Email);
        $sms = $this->channel(ChannelType::Sms);

        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = $this->recipient($notification);

        // The recipient already had an email delivery (through another email
        // channel row) before the WhatsApp one failed.
        NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $emailA->id,
            'team_id' => $this->team->id,
        ]);
        $failed = NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $primary->id,
            'team_id' => $this->team->id,
            'attempt_number' => 5,
        ]);
        $recipient->update(['phone' => '+5215512345678']);

        $this->runFallback($failed);

        $this->assertSame(0, NotificationDelivery::query()->where('channel_id', $emailB->id)->count());
        $this->assertSame(1, NotificationDelivery::query()->where('channel_id', $sms->id)->count());

        $context = $this->assertSystemLogged('notifications.fallback.chosen', fn (array $c) => $c['result']['channel_id'] === $sms->id);
        $this->assertSame([
            ['channel_type' => 'email', 'outcome' => 'already_used'],
            ['channel_type' => 'sms', 'outcome' => 'chosen'],
        ], $context['calc']['walk']);
        $this->assertSame(['email', 'sms'], $context['calc']['policy_fallback_types']);
        $this->assertStringNotContainsString('5215512345678', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fallback_is_skipped_when_the_recipient_was_already_reached(): void
    {
        $primary = $this->channel(ChannelType::Sms);
        $email = $this->channel(ChannelType::Email);
        $web = $this->channel(ChannelType::Web);

        $failed = $this->failedDelivery($primary, attemptNumber: 5);

        NotificationDelivery::factory()->delivered()->create([
            'notification_id' => $failed->notification_id,
            'recipient_id' => $failed->recipient_id,
            'channel_id' => $web->id,
            'team_id' => $this->team->id,
        ]);

        $this->runFallback($failed);

        $this->assertSame(0, NotificationDelivery::query()->where('channel_id', $email->id)->count());

        $this->assertSystemLogged('notifications.escalation_guard.blocked', fn (array $c) => $c['reason'] === 'recipient_reached'
            && $c['input']['stage'] === 'fallback_job'
            && $c['calc']['reached_elsewhere'] === true);
        $this->assertSystemNotLogged('notifications.fallback.chosen');
    }

    public function test_fallback_without_an_address_for_the_channel_is_recorded_as_skipped(): void
    {
        $this->usePolicy(fallback: [ChannelType::Sms]);

        $primary = $this->channel(ChannelType::Email);
        $sms = $this->channel(ChannelType::Sms);
        $failed = $this->failedDelivery($primary, attemptNumber: 5); // recipient has no phone

        $this->bindCapturingDriver(DeliveryResult::success('ok'));
        $this->runFallback($failed);

        $skipped = NotificationDelivery::query()->where('channel_id', $sms->id)->sole();
        $this->assertSame(DeliveryStatus::Skipped, $skipped->status);
        $this->assertSame([], $this->sent);

        $context = $this->assertSystemLogged('notifications.fallback.exhausted', fn (array $c) => $c['reason'] === 'no_fallback_channel'
            && $c['input'] === ['failed_delivery_id' => $failed->id]);
        $this->assertSame([['channel_type' => 'sms', 'outcome' => 'no_address']], $context['calc']['walk']);
        $this->assertSame('warning', $this->systemLogEntries('notifications.fallback.exhausted')[0]['level']);
        $this->assertSystemNotLogged('notifications.fallback.chosen');
    }

    public function test_fallback_without_any_policy_channel_is_recorded_as_exhausted(): void
    {
        $this->usePolicy(fallback: []);

        $primary = $this->channel(ChannelType::Sms);
        $this->channel(ChannelType::Email);
        $failed = $this->failedDelivery($primary, attemptNumber: 5);

        $this->bindCapturingDriver(DeliveryResult::success('ok'));
        $this->runFallback($failed);

        $this->assertSame([], $this->sent);
        $this->assertSame(1, NotificationDelivery::query()->where('notification_id', $failed->notification_id)->count());

        $this->assertSystemLogged('notifications.fallback.exhausted', fn (array $c) => $c['reason'] === 'no_fallback_channel'
            && $c['calc']['policy_fallback_types'] === []
            && $c['calc']['used_types'] === ['sms']
            && $c['calc']['walk'] === []);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fallback_to_sms_uses_the_recipient_phone(): void
    {
        $this->usePolicy(fallback: [ChannelType::Sms]);
        $this->bindCapturingDriver(DeliveryResult::success('ok'));

        $primary = $this->channel(ChannelType::Email);
        $this->channel(ChannelType::Sms);
        $failed = $this->failedDelivery($primary, attemptNumber: 5);
        $failed->recipient->update(['phone' => '+5215512345678']);

        $this->runFallback($failed);

        $this->assertCount(1, $this->sent);
        $this->assertSame('+5215512345678', $this->sent[0]->address);
    }

    /**
     * Recomputes the logged delay from its logged terms and checks it against
     * the delay of the job actually pushed.
     *
     * @return array<string, mixed>
     */
    private function assertRetryScheduledMatchesPushedDelay(NotificationDelivery $delivery): array
    {
        $context = $this->assertSystemLogged('notifications.retry.scheduled', fn (array $c) => $c['input']['delivery_id'] === $delivery->id);

        $delays = $context['calc']['delays_seconds'];
        $step = max(0, min($context['calc']['attempt_number'], count($delays)) - 1);

        $this->assertSame($step, $context['calc']['step']);
        $this->assertSame($delays[$step], $context['result']['delay_seconds']);
        Queue::assertPushed(
            RetryNotificationDeliveryJob::class,
            fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id && $job->delay === $context['result']['delay_seconds'],
        );

        return $context;
    }

    private function channel(ChannelType $type): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'channel_type' => $type,
            'provider' => in_array($type, [ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice], true) ? 'twilio' : $type->value,
            'is_active' => true,
        ]);
    }

    private function recipient(Notification $notification): NotificationRecipient
    {
        return NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $this->team->id,
            'address' => 'ops@example.com',
            'email' => 'ops@example.com',
            'phone' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $notificationAttributes
     */
    private function failedDelivery(
        NotificationChannel $channel,
        int $attemptNumber,
        ?array $payload = null,
        array $notificationAttributes = [],
    ): NotificationDelivery {
        $notification = Notification::factory()->create(['team_id' => $this->team->id, ...$notificationAttributes]);
        $recipient = $this->recipient($notification);

        return NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $this->team->id,
            'attempt_number' => $attemptNumber,
            'payload_json' => $payload,
        ]);
    }

    private function runRetry(NotificationDelivery $delivery): void
    {
        app()->call([new RetryNotificationDeliveryJob($delivery->id), 'handle']);
    }

    private function runFallback(NotificationDelivery $delivery): void
    {
        app()->call([new FallbackNotificationChannelJob($delivery->id), 'handle']);
    }

    private function fireFailureEvent(NotificationDelivery $delivery): void
    {
        event(new NotificationFailed(
            $delivery->team_id,
            $delivery->notification_id,
            $delivery->id,
            NotificationChannel::query()->find($delivery->channel_id)->channel_type->value,
            'provider bounced',
        ));
    }

    /**
     * @param  list<ChannelType>  $fallback
     */
    private function usePolicy(array $fallback): void
    {
        $this->app->instance(TenantNotificationPoliciesResolver::class, new class($fallback) implements TenantNotificationPoliciesResolver
        {
            /**
             * @param  list<ChannelType>  $fallback
             */
            public function __construct(private readonly array $fallback) {}

            public function resolve(Team $team): TenantNotificationPolicy
            {
                return new TenantNotificationPolicy(
                    allowedChannels: ChannelType::cases(),
                    criticalChannels: ChannelType::cases(),
                    fallbackChannels: $this->fallback,
                );
            }
        });
    }

    private function bindCapturingDriver(DeliveryResult $result): void
    {
        $test = $this;

        $this->app->instance(ChannelDriverRegistry::class, new class($result, $test) implements ChannelDriverRegistry
        {
            public function __construct(private readonly DeliveryResult $result, private readonly RetryAndFallbackTest $test) {}

            public function driverFor(ChannelType $channelType): NotificationDriver
            {
                return new class($this->result, $this->test) implements NotificationDriver
                {
                    public function __construct(private readonly DeliveryResult $result, private readonly RetryAndFallbackTest $test) {}

                    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
                    {
                        $this->test->recordSent($notification);

                        return $this->result;
                    }
                };
            }
        });
    }

    public function recordSent(RenderedNotification $notification): void
    {
        $this->sent[] = $notification;
    }
}
