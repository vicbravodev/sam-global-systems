<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Access\Actions\SendPhoneOtp;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Actions\RefreshNotificationStatus;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\TwilioSandbox;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Delivery-layer guards against useless or wrongly billed sends: address
 * validation per channel, aggregate status, OTP metering, sandbox mode and
 * the idempotent notification create.
 */
class DeliveryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);
        $this->team = $user->currentTeam;
    }

    public function test_messaging_channel_is_skipped_when_the_address_is_not_a_phone(): void
    {
        $this->bindDriver(DeliveryResult::success('never'));
        NotificationChannel::factory()->sms()->create();

        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'priority' => NotificationPriority::Normal,
            'payload_json' => [
                'force_channels' => ['sms'],
                'recipients' => [[
                    'recipient_type' => RecipientType::ExternalContact->value,
                    'address' => 'ops@example.com',
                    'phone' => 'ops@example.com',
                ]],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::query()->where('notification_id', $notification->id)->sole();
        $this->assertSame(DeliveryStatus::Skipped, $delivery->status);
        $this->assertStringContainsString('E.164', (string) $delivery->error_message);
        $this->assertSame(NotificationStatus::Cancelled, $notification->fresh()->status);
    }

    public function test_notification_status_is_computed_per_recipient(): void
    {
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $a = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $b = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $sms = NotificationChannel::factory()->sms()->create();
        $email = NotificationChannel::factory()->email()->create();

        $failedSms = $this->deliveryFor($notification, $a, $sms, DeliveryStatus::Failed);
        $this->deliveryFor($notification, $b, $sms, DeliveryStatus::Queued);
        $refresh = fn () => app(RefreshNotificationStatus::class)->execute($notification)->status;

        // A reached (queued), B's only delivery failed → partial.
        $this->assertSame(NotificationStatus::PartiallySent, $refresh());

        // A fallback reaching B turns the notification into sent.
        $this->deliveryFor($notification, $a, $email, DeliveryStatus::Delivered);
        $this->assertSame(NotificationStatus::Sent, $refresh());
        $this->assertNotNull($notification->fresh()->sent_at);

        // Everything failed → failed.
        NotificationDelivery::query()->where('notification_id', $notification->id)->update(['status' => DeliveryStatus::Failed]);
        $this->assertSame(NotificationStatus::Failed, $refresh());

        // A pending retry keeps it queued.
        $failedSms->update(['status' => DeliveryStatus::Sending]);
        $this->assertSame(NotificationStatus::Queued, $refresh());
    }

    public function test_failed_otp_send_is_not_metered(): void
    {
        $this->bindDriver(DeliveryResult::failure('twilio sms error: bad number', permanent: true, providerErrorCode: '21211'));
        NotificationChannel::factory()->sms()->create();

        $result = app(SendPhoneOtp::class)->execute(auth()->user(), $this->team->id);

        $this->assertFalse($result->ok);
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, MessagingCharge::query()->count());
    }

    public function test_accepted_otp_is_metered_and_its_twilio_cost_tracked(): void
    {
        config()->set('services.twilio.account_sid', 'AC_PLATFORM');
        config()->set('services.twilio.auth_token', 'tok');
        config()->set('services.twilio.sms_from', '+15550001111');

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->once()->andReturn((object) ['sid' => 'SM_OTP_1', 'status' => 'queued', 'numSegments' => '1']);
        $this->app->instance(TwilioMessenger::class, $messenger);

        NotificationChannel::factory()->sms()->create();

        $this->assertTrue(app(SendPhoneOtp::class)->execute(auth()->user(), $this->team->id)->ok);

        $meterId = UsageMeter::query()->where('code', 'otp_sms_sent')->value('id');
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('usage_meter_id', $meterId)->count());

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_OTP_1')->sole();
        $this->assertSame(MessagingChargeSource::Otp, $charge->source_type);
        $this->assertSame($this->team->id, $charge->team_id);
    }

    public function test_sandbox_simulates_twilio_without_network_and_is_off_in_production(): void
    {
        config()->set('services.twilio.sandbox', true);

        $messenger = app(TwilioMessenger::class);
        $caller = app(TwilioVoiceCaller::class);

        $delivered = $messenger->createMessage('+5215512345678', ['body' => 'hola']);
        $undelivered = $messenger->createMessage('+5215512345670', ['body' => 'hola']);
        $unanswered = $caller->createCall('+5215512345671', '+15550003333', []);

        $this->assertSame(34, strlen($delivered->sid));
        $this->assertSame('delivered', $messenger->fetchMessage($delivered->sid)->status);
        $this->assertSame('undelivered', $messenger->fetchMessage($undelivered->sid)->status);
        $this->assertSame(30003, $messenger->fetchMessage($undelivered->sid)->errorCode);
        $this->assertSame('no-answer', $caller->fetchCall($unanswered->sid)->status);
        $this->assertSame('completed', $caller->fetchCall($caller->createCall('+5215512345672', '+1', [])->sid)->status);

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(TwilioSandbox::enabled());
    }

    public function test_concurrent_create_with_the_same_event_key_returns_the_existing_row(): void
    {
        $winner = null;

        $racing = false;

        // Simulate losing the race: the existence check saw nothing, and a
        // concurrent worker commits the same (team, event_key) right after
        // it, before our INSERT (which runs in its own savepoint).
        DB::listen(function (QueryExecuted $query) use (&$winner, &$racing) {
            if ($racing || $winner !== null
                || ! str_starts_with(strtolower($query->sql), 'select')
                || ! str_contains($query->sql, 'notifications')
                || ! in_array('incident_created:1', $query->bindings, true)) {
                return;
            }

            $racing = true;
            $winner = Notification::withoutEvents(fn () => Notification::factory()->create([
                'team_id' => $this->team->id,
                'event_key' => 'incident_created:1',
            ]));
        });

        $result = app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: '1',
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:1',
            dispatchJob: false,
        );

        $this->assertNotNull($winner);
        $this->assertSame($winner->id, $result->id);
        $this->assertSame(1, Notification::query()->where('event_key', 'incident_created:1')->count());
    }

    private function deliveryFor(Notification $notification, NotificationRecipient $recipient, NotificationChannel $channel, DeliveryStatus $status): NotificationDelivery
    {
        return NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $this->team->id,
            'status' => $status,
        ]);
    }

    private function bindDriver(DeliveryResult $result): void
    {
        $this->app->instance(ChannelDriverRegistry::class, new class($result) implements ChannelDriverRegistry
        {
            public function __construct(private readonly DeliveryResult $result) {}

            public function driverFor(ChannelType $channelType): NotificationDriver
            {
                return new class($this->result) implements NotificationDriver
                {
                    public function __construct(private readonly DeliveryResult $result) {}

                    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
                    {
                        return $this->result;
                    }
                };
            }
        });
    }
}
